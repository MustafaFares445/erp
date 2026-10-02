<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryPostingCommand;
use App\Data\Purchasing\PurchaseOrderWorkflowData;
use App\Enums\MovementType;
use App\Enums\OrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Filament\Resources\OutboundFulfillments\OutboundFulfillmentResource;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderInfolist;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Logistics\OutboundFulfillmentService;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function batch73PostingCommand(array $overrides = []): InventoryPostingCommand
{
    return new InventoryPostingCommand(...[
        'productVariantId' => 1,
        'warehouseId' => 1,
        'onHandBaseQuantityDelta' => '1.000000',
        'reservedBaseQuantityDelta' => '0.000000',
        'damagedBaseQuantityDelta' => '0.000000',
        'movementType' => MovementType::Adjustment,
        'movementBaseQuantityDelta' => '1.000000',
        'sourceType' => 'batch73',
        'sourceId' => 1,
        'actorId' => null,
        ...$overrides,
    ]);
}

function batch73FindComponent(iterable $components, string $name): mixed
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

                $found = batch73FindComponent($children, $name);

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

it('covers every outbound fulfillment queue filter arm', function (): void {
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

    $table = OutboundFulfillmentResource::table(Table::make($owner));
    $filter = $table->getFilters()['queue'] ?? null;

    expect($filter)->toBeInstanceOf(SelectFilter::class);

    foreach (['awaiting_allocation', 'supply_blocked', 'ready', 'in_transit', 'delivered'] as $value) {
        $query = Order::query();
        $filter->apply($query, ['value' => $value, 'isActive' => true]);
        expect($query->toSql())->not->toBe('');
    }
});

it('covers outbound fulfillment shipment URL when a shipment exists', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $shipment = Shipment::factory()->create([
        'inventory_operation_id' => $delivery->getKey(),
    ]);
    $delivery->load('shipment');

    $schema = OutboundFulfillmentResource::infolist(Schema::make());
    $entry = batch73FindComponent(
        $schema->getComponents(withActions: false, withHidden: true),
        'shipment.tracking_number',
    );

    expect($entry)->not->toBeNull();

    $urlProperty = new ReflectionProperty($entry, 'url');
    $url = $urlProperty->getValue($entry);

    expect($url)->toBeInstanceOf(Closure::class)
        ->and($url($delivery))->toContain((string) $shipment->getKey());
});

it('covers outbound suggestion aggregation including a fully planned line', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Released]);
    $variant = ProductVariant::factory()->machine()->create();

    OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 1,
        'base_quantity' => 1,
        'short_closed_base_quantity' => 1,
        'unit_id' => $variant->unit_id,
    ]);
    OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 1,
        'base_quantity' => 1,
        'short_closed_base_quantity' => 0,
        'unit_id' => $variant->unit_id,
    ]);

    $warehouse = Warehouse::factory()->create();
    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'available_quantity' => 1,
    ]);
    SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    $suggestions = app(OutboundFulfillmentService::class)->suggest($order);

    expect($suggestions)->not->toBe([]);
});

it('covers soft-deleted variant and warehouse guards during outbound planning', function (): void {
    $actor = User::factory()->create();

    $variant = ProductVariant::factory()->machine()->create();
    $warehouse = Warehouse::factory()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Released]);
    OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 1,
        'base_quantity' => 1,
        'short_closed_base_quantity' => 0,
        'unit_id' => $variant->unit_id,
    ]);
    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'available_quantity' => 1,
    ]);

    $variantId = (int) $variant->getKey();
    $warehouseId = (int) $warehouse->getKey();
    $variant->delete();

    expect(fn () => app(OutboundFulfillmentService::class)->plan($actor, $order, [[
        'warehouse_id' => $warehouseId,
        'assignments' => [[
            'product_variant_id' => $variantId,
            'quantity' => 1,
            'inventory_lot_id' => null,
            'serialized_inventory_unit_ids' => [],
        ]],
    ]]))->toThrow(ValidationException::class, 'planned product variant is unavailable');

    $variant2 = ProductVariant::factory()->machine()->create();
    $warehouse2 = Warehouse::factory()->create();
    $order2 = Order::factory()->create(['status' => OrderStatus::Released]);
    OrderLine::factory()->for($order2)->for($variant2, 'productVariant')->create([
        'quantity' => 1,
        'base_quantity' => 1,
        'short_closed_base_quantity' => 0,
        'unit_id' => $variant2->unit_id,
    ]);
    InventoryStock::factory()->for($variant2, 'productVariant')->for($warehouse2, 'warehouse')->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'available_quantity' => 1,
    ]);

    $warehouse2Id = (int) $warehouse2->getKey();
    $warehouse2->delete();

    expect(fn () => app(OutboundFulfillmentService::class)->plan($actor, $order2, [[
        'warehouse_id' => $warehouse2Id,
        'assignments' => [[
            'product_variant_id' => (int) $variant2->getKey(),
            'quantity' => 1,
            'inventory_lot_id' => null,
            'serialized_inventory_unit_ids' => [],
        ]],
    ]]))->toThrow(DomainException::class, 'selected warehouse is unavailable');
});

it('covers purchase-order workflow-step owner branches', function (): void {
    $fake = new class
    {
        public string $nextOwner = 'Purchasing';

        public function project(PurchaseOrder $order): PurchaseOrderWorkflowData
        {
            return new PurchaseOrderWorkflowData(
                businessState: 'Accepted',
                supplierState: 'Confirmed',
                logisticsState: 'Pending',
                financialState: 'Pending',
                orderedBaseQuantity: '10.000000',
                confirmedBaseQuantity: '10.000000',
                backorderedBaseQuantity: '0.000000',
                unavailableBaseQuantity: '0.000000',
                allocatedBaseQuantity: '0.000000',
                receiptInProgressBaseQuantity: '0.000000',
                receivedBaseQuantity: '0.000000',
                remainingConfirmedBaseQuantity: '10.000000',
                billTotal: '0.00',
                paidTotal: '0.00',
                outstandingTotal: '0.00',
                blocker: null,
                nextOwner: $this->nextOwner,
                nextAction: 'Continue',
            );
        }
    };

    app()->instance(PurchaseOrderWorkflowService::class, $fake);

    try {
        $method = new ReflectionMethod(PurchaseOrderInfolist::class, 'workflowSteps');

        foreach ([
            'Purchasing' => 3,
            'Inventory' => 4,
            'Accounting' => 5,
            'Other' => 4,
        ] as $owner => $currentIndex) {
            $fake->nextOwner = $owner;
            $order = PurchaseOrder::factory()->create([
                'status' => PurchaseOrderStatus::Accepted,
                'sent_at' => now(),
            ]);

            $steps = $method->invoke(null, $order);

            expect($steps[$currentIndex]['state'])->toBe('current');
        }
    } finally {
        app()->forgetInstance(PurchaseOrderWorkflowService::class);
    }
});

it('covers inventory posting disposed snapshot serialized no-op and transfer mismatch', function (): void {
    $service = app(InventoryPostingService::class);

    $saleable = new InventoryConditionBalance;
    $saleable->forceFill([
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '3.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    $key = new ReflectionMethod(InventoryPostingService::class, 'conditionKey')
        ->invoke($service, 1, 1, StockCondition::Saleable);

    $snapshot = new ReflectionMethod(InventoryPostingService::class, 'conditionSnapshot')
        ->invoke(
            $service,
            batch73PostingCommand([
                'conditionFrom' => StockCondition::Disposed,
                'conditionTo' => StockCondition::Saleable,
            ]),
            [$key => $saleable],
        );

    expect($snapshot['from_on_hand'])->toBeNull()
        ->and($snapshot['to_on_hand'])->toBe('3.000000');

    expect(new ReflectionMethod(InventoryPostingService::class, 'applySerializedTransition')
        ->invoke(
            $service,
            batch73PostingCommand(['serializedInventoryUnitId' => 123]),
            [],
            [],
        ))->toBeNull();

    expect(fn (): mixed => new ReflectionMethod(InventoryPostingService::class, 'assertConditionMutation')
        ->invoke(
            $service,
            batch73PostingCommand([
                'conditionFrom' => StockCondition::Saleable,
                'conditionTo' => StockCondition::Quarantine,
                'conditionTransferBaseQuantity' => '1.000000',
                'onHandBaseQuantityDelta' => '1.000000',
                'damagedBaseQuantityDelta' => '0.000000',
            ]),
        ))->toThrow(DomainException::class, 'do not reconcile');
});
