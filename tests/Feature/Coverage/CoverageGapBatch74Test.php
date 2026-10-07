<?php

declare(strict_types=1);

use App\Enums\CustomerProfileChangeRequestStatus;
use App\Enums\OperationType;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\CustomerProfileChangeRequestsRelationManager;
use App\Filament\Resources\InventoryOperations\Schemas\OperationLinesRepeater;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\PurchaseOrders\RelationManagers\AllocationsRelationManager;
use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\Schemas\TicketInfolist;
use App\Models\CustomerProfile;
use App\Models\CustomerProfileChangeRequest;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function batch74Property(object $object, string $name): mixed
{
    $reflection = new ReflectionClass($object);

    do {
        if ($reflection->hasProperty($name)) {
            return $reflection->getProperty($name)->getValue($object);
        }
    } while ($reflection = $reflection->getParentClass());

    throw new LogicException("Property {$name} not found.");
}

function batch74FindComponent(iterable $components, string $name): mixed
{
    foreach ($components as $component) {
        if (method_exists($component, 'getName') && $component->getName() === $name) {
            return $component;
        }

        try {
            $property = new ReflectionProperty($component, 'childComponents');
            $sets = $property->getValue($component);
            foreach (is_array($sets) ? $sets : [] as $children) {
                if (! is_iterable($children)) {
                    continue;
                }

                $found = batch74FindComponent($children, $name);
                if ($found !== null) {
                    return $found;
                }
            }
        } catch (ReflectionException) {
            // Leaf component.
        }
    }

    return null;
}

function batch74OperationComponents(): array
{
    $repeater = OperationLinesRepeater::make();
    $components = [];

    $collect = static function (iterable $children) use (&$collect, &$components): void {
        foreach ($children as $component) {
            if (method_exists($component, 'getName')) {
                $name = $component->getName();
                if (is_string($name) && $name !== '') {
                    $components[$name] = $component;
                }
            }

            try {
                $property = new ReflectionProperty($component, 'childComponents');
                $sets = $property->getValue($component);
                foreach (is_array($sets) ? $sets : [] as $set) {
                    if (is_iterable($set)) {
                        $collect($set);
                    }
                }
            } catch (ReflectionException) {
                // Leaf.
            }
        }
    };

    $property = new ReflectionProperty($repeater, 'childComponents');
    foreach ((array) $property->getValue($repeater) as $children) {
        if (is_iterable($children)) {
            $collect($children);
        }
    }

    return $components;
}

it('covers return document URLs and digit-string integer normalization', function (): void {
    $operation = InventoryOperation::factory()->delivery()->create();
    $purchaseOrder = PurchaseOrder::factory()->create();
    $return = InventoryReturn::factory()->customer()->create([
        'original_inventory_operation_id' => $operation->getKey(),
        'original_purchase_order_id' => $purchaseOrder->getKey(),
    ]);

    $schema = ReturnResource::infolist(Schema::make());
    $operationEntry = batch74FindComponent(
        $schema->getComponents(withActions: false, withHidden: true),
        'originalOperation.operation_number',
    );
    $poEntry = batch74FindComponent(
        $schema->getComponents(withActions: false, withHidden: true),
        'originalPurchaseOrder.purchase_order_number',
    );

    expect($operationEntry)->not->toBeNull()
        ->and($poEntry)->not->toBeNull();

    $operationUrl = batch74Property($operationEntry, 'url');
    $poUrl = batch74Property($poEntry, 'url');

    expect($operationUrl($return))->toContain((string) $operation->getKey())
        ->and($poUrl($return))->toContain((string) $purchaseOrder->getKey());

    $owner = new class extends Component implements HasTable
    {
        use InteractsWithTable;

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    $table = ReturnResource::table(Table::make($owner));
    $operationColumn = $table->getColumn('originalOperation.operation_number');
    $poColumn = $table->getColumn('originalPurchaseOrder.purchase_order_number');

    expect(batch74Property($operationColumn, 'url')($return))->toContain((string) $operation->getKey())
        ->and(batch74Property($poColumn, 'url')($return))->toContain((string) $purchaseOrder->getKey())
        ->and(new ReflectionMethod(ReturnResource::class, 'nullableInteger')->invoke(null, '42'))->toBe(42);
});

it('covers ticket paused resolution maintenance next action and breached response labels', function (): void {
    $actor = User::factory()->admin()->create();
    $paused = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'service_path' => TicketServicePath::Maintenance,
        'live_at' => now()->subHour(),
        'waiting_customer_since' => now()->subMinutes(10),
    ]);

    Livewire::actingAs($actor)
        ->test(ViewTicket::class, ['record' => $paused->getKey()])
        ->assertSee('Resolution clock paused while waiting for the customer.');

    $nextAction = new ReflectionMethod(TicketInfolist::class, 'nextAction');
    expect($nextAction->invoke(null, $paused))->toBe('Continue support / raise maintenance job');

    $firstResponse = new ReflectionMethod(TicketInfolist::class, 'firstResponseState');

    $respondedLate = Ticket::factory()->make([
        'first_response_at' => now(),
        'response_breached' => true,
    ]);
    $overdue = Ticket::factory()->make([
        'first_response_at' => null,
        'response_breached' => true,
    ]);

    expect($firstResponse->invoke(null, $respondedLate))->toContain('SLA breached')
        ->and($firstResponse->invoke(null, $overdue))->toBe('Overdue');
});

it('covers empty customer change summary unauthenticated action no-ops and invalid owner guard', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $request = CustomerProfileChangeRequest::factory()->create([
        'customer_id' => $customer->getKey(),
        'requested_changes' => [],
        'status' => CustomerProfileChangeRequestStatus::Pending,
    ]);

    Livewire::actingAs($admin)
        ->test(CustomerProfileChangeRequestsRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])
        ->assertTableColumnStateSet('requested_changes', '—', $request);

    $manager = new CustomerProfileChangeRequestsRelationManager;
    $manager->ownerRecord = $customer;

    auth()->logout();

    $approve = new ReflectionMethod(CustomerProfileChangeRequestsRelationManager::class, 'approveAction')->invoke($manager);
    ($approve->getActionFunction())($request, ['note' => 'Should be ignored']);

    $reject = new ReflectionMethod(CustomerProfileChangeRequestsRelationManager::class, 'rejectAction')->invoke($manager);
    ($reject->getActionFunction())($request, ['note' => 'Should also be ignored']);

    expect($request->refresh()->status)->toBe(CustomerProfileChangeRequestStatus::Pending);

    $manager->ownerRecord = User::factory()->create();
    expect(fn (): mixed => new ReflectionMethod(CustomerProfileChangeRequestsRelationManager::class, 'ownerCustomer')->invoke($manager))
        ->toThrow(LogicException::class, 'Expected a CustomerProfile owner record');
});

it('covers create-order unauthenticated creation guard and no-customer price preview', function (): void {
    auth()->logout();

    $page = new ReflectionClass(CreateOrder::class);
    $instance = $page->newInstanceWithoutConstructor();

    expect(fn (): mixed => new ReflectionMethod(CreateOrder::class, 'handleRecordCreation')->invoke($instance, []))
        ->toThrow(AccessDeniedHttpException::class);

    $variant = ProductVariant::factory()->create(['base_price' => 80]);
    $preview = new ReflectionMethod(CreateOrder::class, 'pricePreview')
        ->invoke($instance, 'not-a-customer', $variant->getKey(), null, null);

    expect($preview)->toContain('80.00');
});

it('covers allocation used-warehouse filter allocation fallback total and negative remaining clamp', function (): void {
    $order = PurchaseOrder::factory()->create();
    $poLine = PurchaseOrderLine::factory()->for($order)->create([
        'base_quantity' => '5.000000',
        'received_base_quantity' => null,
    ]);
    $inbound = PurchaseInbound::factory()->for($order)->create();
    $line = PurchaseInboundLine::factory()->create([
        'purchase_inbound_id' => $inbound->getKey(),
        'purchase_order_line_id' => $poLine->getKey(),
    ]);

    $used = Warehouse::factory()->create(['is_active' => true]);
    $available = Warehouse::factory()->create(['is_active' => true]);
    PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $line->getKey(),
        'warehouse_id' => $used->getKey(),
        'allocated_base_quantity' => '3.000000',
    ]);

    $options = new ReflectionMethod(AllocationsRelationManager::class, 'availableWarehouseOptions')
        ->invoke(null, $line);

    expect($options)->not->toHaveKey($used->getKey())
        ->toHaveKey($available->getKey());

    $received = new ReflectionMethod(AllocationsRelationManager::class, 'receivedForLine')
        ->invoke(null, $line);
    expect($received)->toBe('0.000000');

    $poLine->forceFill([
        'base_quantity' => '2.000000',
        'received_base_quantity' => '5.000000',
    ])->save();

    expect(new ReflectionMethod(AllocationsRelationManager::class, 'remainingForLine')->invoke(null, $line->fresh()))
        ->toBe('0.000000');
});

it('covers operation line quantity clamp missing serial variant and soft-deleted unit option', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);

    $components = batch74OperationComponents();

    /** @var TextInput $quantity */
    $quantity = $components['quantity'];
    $hooks = batch74Property($quantity, 'afterStateUpdated');

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(static fn (string $path): mixed => match ($path) {
        '../../operation_type' => OperationType::Delivery->value,
        'product_variant_id' => $variant->getKey(),
        '../../source_warehouse_id' => $warehouse->getKey(),
        default => null,
    });
    $set = Mockery::mock(Set::class);
    $set->shouldReceive('__invoke')->once()->with('quantity', 5.0);

    $hooks[0]($get, $set, 8);

    /** @var Select $serial */
    $serial = $components['serialized_inventory_unit_id'];
    $createOption = batch74Property($serial, 'createOptionUsing');
    $missingVariantGet = Mockery::mock(Get::class);
    $missingVariantGet->shouldReceive('__invoke')->with('product_variant_id')->andReturn(null);

    expect(fn () => $createOption(['serial_number' => 'B74-SERIAL'], $missingVariantGet))
        ->toThrow(DomainException::class);

    $baseVariantUnit = $variant->variantUnits()->with('unit')->firstOrFail();
    $baseVariantUnit->unit?->delete();

    $options = new ReflectionMethod(OperationLinesRepeater::class, 'unitOptions')
        ->invoke(null, $variant->getKey());

    expect($options)->toBe([]);
});
