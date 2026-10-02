<?php

declare(strict_types=1);

use App\Enums\InventoryReturnType;
use App\Enums\PaymentMethodType;
use App\Filament\Resources\CustomerQuotationRequests\Pages\CreateCustomerQuotationRequest;
use App\Filament\Resources\PaymentMethods\Schemas\PaymentMethodForm;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderInfolist;
use App\Filament\Resources\Quotations\Schemas\QuotationLinesRepeater;
use App\Filament\Resources\Returns\Pages\ManageReturns;
use App\Filament\Resources\Returns\Pages\ViewReturn;
use App\Filament\Resources\ServiceRecords\Tables\ServiceRecordsTable;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Models\CustomerQuotationRequest;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Crm\CustomerQuotationRequestService;
use App\Services\Inventory\InventoryReturnService;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function batch78Property(object $object, string $name): mixed
{
    $reflection = new ReflectionClass($object);

    do {
        if ($reflection->hasProperty($name)) {
            return $reflection->getProperty($name)->getValue($object);
        }
    } while ($reflection = $reflection->getParentClass());

    throw new LogicException("Property {$name} not found.");
}

function batch78FindComponent(iterable $components, callable $predicate): mixed
{
    foreach ($components as $component) {
        if ($predicate($component)) {
            return $component;
        }

        try {
            $property = new ReflectionProperty($component, 'childComponents');

            foreach ((array) $property->getValue($component) as $children) {
                if (! is_iterable($children)) {
                    continue;
                }

                $found = batch78FindComponent($children, $predicate);

                if ($found !== null) {
                    return $found;
                }
            }
        } catch (ReflectionException) {
            // Leaf.
        }
    }

    return null;
}

it('covers customer quotation request delivery address and non-array line normalization', function (): void {
    $customer = CustomerProfile::factory()->create();
    $address = CustomerDeliveryAddress::factory()->create([
        'customer_profile_id' => $customer->getKey(),
    ]);

    $fake = new class
    {
        /** @var array<string, mixed> */
        public array $captured = [];

        public function submit(
            CustomerProfile $customer,
            array $lines,
            ?CustomerDeliveryAddress $deliveryAddress = null,
            ?string $notes = null,
            string $sourceChannel = 'dashboard',
        ): CustomerQuotationRequest {
            $this->captured = ['customer' => $customer, 'lines' => $lines, 'deliveryAddress' => $deliveryAddress, 'notes' => $notes, 'sourceChannel' => $sourceChannel];

            return new CustomerQuotationRequest;
        }
    };

    app()->instance(CustomerQuotationRequestService::class, $fake);

    try {
        $page = new ReflectionClass(CreateCustomerQuotationRequest::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CreateCustomerQuotationRequest::class, 'handleRecordCreation');

        $result = $method->invoke($page, [
            'customer_id' => $customer->getKey(),
            'customer_delivery_address_id' => (string) $address->getKey(),
            'lines' => [
                'skip-me',
                [
                    'product_variant_id' => 42,
                    'requested_quantity' => '3',
                    'customer_note' => 'Coverage line',
                ],
            ],
            'notes' => 'Coverage note',
        ]);

        expect($result)->toBeInstanceOf(CustomerQuotationRequest::class)
            ->and($fake->captured['deliveryAddress']->is($address))->toBeTrue()
            ->and($fake->captured['lines'])->toHaveCount(1)
            ->and($fake->captured['lines'][0]['product_variant_id'])->toBe(42);
    } finally {
        app()->forgetInstance(CustomerQuotationRequestService::class);
    }
});

it('covers Stripe helper text closures without an external provider call', function (): void {
    $schema = PaymentMethodForm::configure(Schema::make());

    foreach ([
        'type' => 'A Stripe method never requires payment proof',
        'is_active' => 'Always active',
    ] as $fieldName => $expected) {
        $field = batch78FindComponent(
            $schema->getComponents(withActions: false, withHidden: true),
            fn (mixed $component): bool => method_exists($component, 'getName') && $component->getName() === $fieldName,
        );

        expect($field)->not->toBeNull();

        $children = batch78Property($field, 'childComponents');
        $wrapper = $children['below_content'] ?? null;

        expect($wrapper)->toBeInstanceOf(Closure::class);

        $original = new ReflectionFunction($wrapper)->getStaticVariables()['text'] ?? null;
        expect($original)->toBeInstanceOf(Closure::class);

        $get = Mockery::mock(Get::class);
        $get->shouldReceive('__invoke')->with('type')->andReturn(PaymentMethodType::Stripe->value);

        expect((string) $original($get))->toContain($expected);
    }
});

it('covers return source-invoice fallback when the original operation is not a sales order', function (): void {
    $customer = CustomerProfile::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->done()->create();
    $return = InventoryReturn::factory()->customer()->create([
        'customer_id' => $customer->getKey(),
        'original_inventory_operation_id' => $operation->getKey(),
    ]);

    $page = new ReflectionClass(ViewReturn::class)->newInstanceWithoutConstructor();
    $sourceInvoice = new ReflectionMethod(ViewReturn::class, 'sourceInvoice');

    expect($sourceInvoice->invoke($page, $return))->toBeNull();
});

it('covers the unauthenticated service-record table actor guard', function (): void {
    auth()->logout();

    expect(fn (): mixed => new ReflectionMethod(ServiceRecordsTable::class, 'currentActor')->invoke(null))
        ->toThrow(LogicException::class, 'authenticated User');
});

it('covers quotation floor-reason false when the selected variant has no floor', function (): void {
    Gate::before(static fn (): bool => true);
    $this->actingAs(User::factory()->create());

    $variant = ProductVariant::factory()->create([
        'min_price' => null,
    ]);

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(static fn (string $path): mixed => match ($path) {
        'price_floor_override_id' => null,
        'product_variant_id' => $variant->getKey(),
        default => null,
    });

    expect(new ReflectionMethod(QuotationLinesRepeater::class, 'needsFloorOverrideReason')->invoke(null, $get))
        ->toBeFalse();
});

it('covers the supplier-confirmation-required purchase-order infolist description', function (): void {
    $order = PurchaseOrder::factory()->create([
        'supplier_confirmation_required' => true,
    ]);

    $schema = PurchaseOrderInfolist::configure(Schema::make());

    $section = batch78FindComponent(
        $schema->getComponents(withActions: false, withHidden: true),
        fn (mixed $component): bool => $component instanceof Section
            && (string) $component->getHeading() === (string) __('Supplier response'),
    );

    expect($section)->toBeInstanceOf(Section::class);

    $description = batch78Property($section, 'description');
    expect($description)->toBeInstanceOf(Closure::class)
        ->and($description($order))->toContain('Supplier promises');
});

it('covers supplier-return receipt resolution in the return create action', function (): void {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $receipt = InventoryOperation::factory()->receipt()->done()->create();

    $fake = new class
    {
        public ?InventoryOperation $receipt = null;

        public function createSupplierReturn(
            User $actor,
            Supplier $supplier,
            Warehouse $warehouse,
            ?InventoryOperation $receipt,
            ?int $purchaseOrderId,
            ?string $reason,
            ?string $notes,
        ): InventoryReturn {
            $this->receipt = $receipt;

            return new InventoryReturn;
        }
    };

    app()->instance(InventoryReturnService::class, $fake);

    try {
        $page = new ReflectionClass(ManageReturns::class)->newInstanceWithoutConstructor();
        $actions = new ReflectionMethod(ManageReturns::class, 'getHeaderActions')->invoke($page);
        $create = $actions[0];
        $using = batch78Property($create, 'using');

        expect($using)->toBeInstanceOf(Closure::class);

        $result = $using([
            'return_type' => InventoryReturnType::Supplier->value,
            'warehouse_id' => $warehouse->getKey(),
            'supplier_id' => $supplier->getKey(),
            'supplier_receipt_id' => (string) $receipt->getKey(),
            'original_purchase_order_id' => null,
        ]);

        expect($result)->toBeInstanceOf(InventoryReturn::class)
            ->and($fake->receipt?->is($receipt))->toBeTrue();
    } finally {
        app()->forgetInstance(InventoryReturnService::class);
    }
});
