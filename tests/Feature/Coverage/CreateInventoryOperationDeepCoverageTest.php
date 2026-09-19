<?php

declare(strict_types=1);

use App\Enums\DeliveryDocument;
use App\Enums\DeliveryType;
use App\Enums\InventoryPermission;
use App\Enums\OperationType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Filament\Resources\InventoryOperations\Pages\CreateInventoryOperation;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryLotService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Orders\DeliveryTypeResolver;
use App\Services\Orders\OrderFulfillmentService;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);
function cioPage(): CreateInventoryOperation
{
    $page = app(CreateInventoryOperation::class);
    $page->boot(
        app(OrderFulfillmentService::class),
        app(DeliveryTypeResolver::class),
        app(InventoryOperationService::class),
        app(InventoryLotService::class),
    );

    return $page;
}

function cioCall(CreateInventoryOperation $page, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod(CreateInventoryOperation::class, $method);

    return $reflection->invokeArgs($page, $arguments);
}

function cioGet(array $values): Get
{
    return new class($values) extends Get
    {
        public function __construct(private array $values) {}

        public function __invoke(string|Component $path = '', bool $isAbsolute = false): mixed
        {
            return $this->values[$path] ?? null;
        }
    };
}
it('covers delivery wizard transformation and location helpers', function (): void {
    config()->set('services.osrm.url', 'https://router.test');
    $page = cioPage();
    $customer = CustomerProfile::factory()->create([
        'address' => 'Dubai, United Arab Emirates',
        'city' => 'Dubai',
        'country' => 'AE',
    ]);
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create(['name' => 'Coverage Product']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->getKey(),
        'name' => 'Coverage Variant',
    ]);

    expect(cioCall($page, 'integer', 5))->toBe(5)
        ->and(cioCall($page, 'integer', '7'))->toBe(7)
        ->and(cioCall($page, 'integer', 'bad'))->toBeNull()
        ->and(cioCall($page, 'stateArray', null))->toBe([])
        ->and(cioCall($page, 'resolvedDeliveryType', DeliveryType::Outer->value))->toBe(DeliveryType::Outer)
        ->and(cioCall($page, 'resolvedDeliveryType', 'bad'))->toBe(DeliveryType::Inner);
    $shipmentState = [[
        'warehouse_id' => (string) $warehouse->getKey(),
        'delivery_type' => 'invalid',
        'assignments' => [[
            'product_id' => (string) $product->getKey(),
            'product_variant_id' => null,
            'quantity' => '2.5',
        ], 'ignored'],
    ], 'ignored'];

    $normalized = cioCall($page, 'normalizedShipments', $shipmentState);
    expect($normalized[0]['delivery_type'])->toBe(DeliveryType::Inner->value)
        ->and($normalized[0]['assignments'][0]['product_variant_id'])->toBe($variant->getKey());

    $products = cioCall($page, 'productsFromShipments', $normalized);
    expect($products)->toBe([[
        'product_variant_id' => $variant->getKey(),
        'quantity' => 2.5,
    ]]);

    expect(cioCall($page, 'selectedWarehouseIds', $shipmentState))->toBe([$warehouse->getKey()])
        ->and(cioCall($page, 'warehouseAddress', $warehouse->getKey()))->toBe($warehouse->address)
        ->and(cioCall($page, 'warehouseAddress', 'bad'))->toBeNull();

    $map = cioCall($page, 'deliveryCustomerMapData', $customer->getKey(), $shipmentState);
    expect($map['customerName'])->toBe($customer->company_name)
        ->and($map['latitude'])->toBeFloat()
        ->and($map['routingServiceUrl'])->toBe('https://router.test');
    $emptyMap = cioCall($page, 'deliveryCustomerMapData', null, []);
    expect($emptyMap['customerName'])->toBeNull()
        ->and(cioCall($page, 'displayCountryName', null))->toBeNull()
        ->and(cioCall($page, 'displayCountryName', 'United Arab Emirates'))->toBe('United Arab Emirates')
        ->and(cioCall($page, 'displayCountryName', 'AE'))->toBeString()
        ->and(cioCall($page, 'customerLocationSummary', $customer))->toBeString();

    expect(cioCall($page, 'assignmentItemLabel', []))->toBeNull()
        ->and(cioCall($page, 'assignmentItemLabel', [
            'product_id' => $product->getKey(),
            'quantity' => 3,
        ]))->toContain('Qty: 3');

    $page->selectedOperationType = OperationType::InternalTransfer;
    expect(cioCall($page, 'forcedOperationType'))->toBe(OperationType::InternalTransfer);
    $page->selectedOperationType = null;
    request()->query->set('operation_type', OperationType::Receipt->value);
    expect(cioCall($page, 'forcedOperationType'))->toBe(OperationType::Receipt);
    request()->query->set('operation_type', 'invalid');
    expect(fn (): mixed => cioCall($page, 'forcedOperationType'))->toThrow(NotFoundHttpException::class);
});
it('covers warehouse stock product variant lot and serial helpers', function (): void {
    $page = cioPage();
    $warehouse = Warehouse::factory()->create(['name' => 'Coverage Warehouse']);
    $product = Product::factory()->create(['name' => 'Multi Product']);
    $variantA = ProductVariant::factory()->create([
        'product_id' => $product->getKey(),
        'name' => 'Variant A',
        'sku' => 'COV-A',
    ]);
    $variantB = ProductVariant::factory()->create([
        'product_id' => $product->getKey(),
        'name' => 'Variant B',
        'sku' => 'COV-B',
    ]);
    InventoryStock::factory()->create([
        'product_variant_id' => $variantA->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'on_hand_quantity' => 10,
        'reserved_quantity' => 2,
        'available_quantity' => 8,
    ]);

    expect(cioCall($page, 'productOptions', $warehouse->getKey()))->toHaveKey($product->getKey())
        ->and(cioCall($page, 'warehouseOptions', [['product_id' => $product->getKey()]]))->toHaveKey($warehouse->getKey())
        ->and(cioCall($page, 'variantsForProduct', $product->getKey()))->toHaveCount(2)
        ->and(cioCall($page, 'hasMultipleVariants', $product->getKey()))->toBeTrue()
        ->and(cioCall($page, 'singleVariantId', $product->getKey()))->toBeNull();
    $singleProduct = Product::factory()->create(['name' => 'Single Product']);
    $singleVariant = ProductVariant::factory()->create([
        'product_id' => $singleProduct->getKey(),
        'name' => 'Single Variant',
        'sku' => 'COV-SINGLE',
    ]);
    InventoryStock::factory()->create([
        'product_variant_id' => $singleVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'on_hand_quantity' => 5,
        'reserved_quantity' => 0,
        'available_quantity' => 5,
    ]);

    expect(cioCall($page, 'singleVariantId', $singleProduct->getKey()))->toBe($singleVariant->getKey())
        ->and(cioCall($page, 'availableQuantity', $singleVariant->getKey(), $warehouse->getKey()))->toBe(5.0)
        ->and(cioCall($page, 'availableQuantity', null, $warehouse->getKey()))->toBeNull();

    $quantityGet = cioGet([
        '../../warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $singleVariant->getKey(),
        'product_id' => $singleProduct->getKey(),
    ]);
    expect(cioCall($page, 'quantityPlaceholder', $quantityGet))->toBe('Available: 5');

    $missingGet = cioGet([]);
    expect(cioCall($page, 'quantityPlaceholder', $missingGet))->toContain('Select a product');
    $machineVariant = ProductVariant::factory()->machine()->create(['name' => 'Machine Variant']);
    $lotVariant = ProductVariant::factory()->expiryMaterial()->create(['name' => 'Lot Variant']);
    InventoryStock::factory()->create([
        'product_variant_id' => $machineVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'available_quantity' => 2,
    ]);
    InventoryStock::factory()->create([
        'product_variant_id' => $lotVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'available_quantity' => 4,
    ]);

    expect(cioCall($page, 'requiresSerials', $machineVariant->getKey()))->toBeTrue()
        ->and(cioCall($page, 'requiresSerials', null))->toBeFalse()
        ->and(cioCall($page, 'requiresLot', $lotVariant->getKey()))->toBeTrue()
        ->and(cioCall($page, 'requiresLot', null))->toBeFalse();

    $lot = InventoryLot::factory()->create([
        'product_variant_id' => $lotVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'lot_number' => 'LOT-COVERAGE',
        'on_hand_quantity' => 4,
        'reserved_quantity' => 0,
    ]);
    expect(cioCall($page, 'lotOptions', $lotVariant->getKey(), $warehouse->getKey()))
        ->toHaveKey($lot->getKey());
    $serial = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $machineVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'serial_number' => 'SER-COVERAGE',
        'iot_number' => 'IOT-COVERAGE',
    ]);
    expect(cioCall($page, 'serializedUnitOptions', $machineVariant->getKey(), $warehouse->getKey()))
        ->toHaveKey($serial->getKey());

    $warningGet = cioGet([
        'warehouse_id' => $warehouse->getKey(),
        'assignments' => [[
            'product_variant_id' => $singleVariant->getKey(),
            'quantity' => 10,
        ]],
    ]);
    expect(cioCall($page, 'hasStockWarning', $warningGet))->toBeTrue()
        ->and((string) cioCall($page, 'stockWarning', $warningGet))->toContain('Not enough stock');

    $noWarningGet = cioGet([
        'warehouse_id' => $warehouse->getKey(),
        'assignments' => [['product_variant_id' => $singleVariant->getKey(), 'quantity' => 1]],
    ]);
    expect(cioCall($page, 'hasStockWarning', $noWarningGet))->toBeFalse();
});
it('covers delivery creation guards and valid persistence', function (): void {
    $page = cioPage();
    expect(fn (): mixed => cioCall($page, 'createDeliveryGroup', []))
        ->toThrow(NotFoundHttpException::class);

    $permission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $role = Role::findOrCreate('coverage-delivery-creator', 'web');
    $role->givePermissionTo($permission);

    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);

    expect(fn (): mixed => cioCall($page, 'createDeliveryGroup', []))
        ->toThrow(ValidationException::class);

    $invalidCustomer = CustomerProfile::factory()->create(['is_active' => false]);
    expect(fn (): mixed => cioCall($page, 'createDeliveryGroup', [
        'customer_id' => $invalidCustomer->getKey(),
    ]))->toThrow(ValidationException::class);
    $customer = CustomerProfile::factory()->create();
    expect(fn (): mixed => cioCall($page, 'createDeliveryGroup', [
        'customer_id' => $customer->getKey(),
        'responsible_id' => 999999,
    ]))->toThrow(ValidationException::class);

    expect(fn (): mixed => cioCall($page, 'createDeliveryGroup', [
        'customer_id' => $customer->getKey(),
        'scheduled_at' => 123,
    ]))->toThrow(ValidationException::class);

    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '5.000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000',
        'reserved_quantity' => '0.000',
        'expires_at' => null,
    ]);
    $delivery = cioCall($page, 'createDeliveryGroup', [
        'customer_id' => $customer->getKey(),
        'responsible_id' => $actor->getKey(),
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'notes' => 'Coverage delivery',
        'shipments' => [[
            'warehouse_id' => $warehouse->getKey(),
            'delivery_type' => DeliveryType::Inner->value,
            'assignments' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 1,
                'inventory_lot_id' => $lot->getKey(),
            ]],
        ]],
    ]);

    expect($delivery)->toBeInstanceOf(InventoryOperation::class)
        ->and($delivery->customer_id)->toBe($customer->getKey());
});
it('covers contextual form title mutation map and document branches', function (): void {
    config()->set('services.osrm.url', 'https://router.test');
    Http::fake([
        '*' => Http::response([
            'routes' => [['geometry' => ['coordinates' => [[55.2708, 25.2048], [55.2710, 25.2050]]]]],
        ], 200),
    ]);

    $page = cioPage();
    $page->isContextualDelivery = true;
    $page->selectedOperationType = OperationType::Delivery;

    expect($page->form(Schema::make($page)))->toBeInstanceOf(Schema::class)
        ->and($page->hasFormWrapper())->toBeFalse()
        ->and($page->getTitle())->toBe('Create Delivery');

    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
    $mutated = cioCall($page, 'mutateFormDataBeforeCreate', [
        'shipments' => [[
            'warehouse_id' => Warehouse::factory()->create()->getKey(),
            'assignments' => [[
                'product_id' => $product->getKey(),
                'quantity' => 2,
            ]],
        ]],
    ]);
    expect($mutated['operation_type'])->toBe(OperationType::Delivery->value)
        ->and($mutated['products'][0]['product_variant_id'])->toBe($variant->getKey());

    $documents = [
        DeliveryDocument::OriginalInvoice->value => ['first.pdf', 5],
    ];
    $method = new ReflectionMethod(CreateInventoryOperation::class, 'extractDeliveryDocuments');
    $args = [&$documents];
    $extracted = $method->invokeArgs($page, $args);
    expect($extracted)->toHaveCount(1)
        ->and($documents)->not->toHaveKey(DeliveryDocument::OriginalInvoice->value);

    $warehouse = Warehouse::factory()->create([
        'latitude' => 25.2048,
        'longitude' => 55.2708,
    ]);
    $customer = CustomerProfile::factory()->create([
        'latitude' => 25.2050,
        'longitude' => 55.2710,
    ]);

    expect(cioCall($page, 'autoDetectedDeliveryType', cioGet([])))->toBe(DeliveryType::Inner)
        ->and(cioCall($page, 'autoDetectedDeliveryType', cioGet([
            'warehouse_id' => $warehouse->getKey(),
            '../../customer_id' => $customer->getKey(),
        ])))->toBeInstanceOf(DeliveryType::class);
});
it('covers remaining stock warning filtered variant and no expiry lot paths', function (): void {
    $page = cioPage();
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();
    $variantA = ProductVariant::factory()->create(['product_id' => $product->getKey(), 'sku' => 'FILTER-A']);
    $variantB = ProductVariant::factory()->create(['product_id' => $product->getKey(), 'sku' => 'FILTER-B']);

    InventoryStock::factory()->for($variantA)->for($warehouse)->create([
        'on_hand_quantity' => '3.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '3.000000',
    ]);
    InventoryStock::factory()->for($variantB)->for($warehouse)->create([
        'on_hand_quantity' => '2.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '2.000000',
    ]);

    expect(cioCall($page, 'productOptions', null))->toHaveKey($product->getKey())
        ->and(cioCall($page, 'variantsForProduct', $product->getKey(), $warehouse->getKey()))->toHaveCount(2)
        ->and(cioCall($page, 'hasMultipleVariants', $product->getKey(), $warehouse->getKey()))->toBeTrue()
        ->and(cioCall($page, 'stockWarnings', cioGet([])))->toBe([])
        ->and(cioCall($page, 'stockWarnings', cioGet([
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [['quantity' => 2]],
        ])))->toBe([]);

    $missingStockVariant = ProductVariant::factory()->create();
    $warnings = cioCall($page, 'stockWarnings', cioGet([
        'warehouse_id' => $warehouse->getKey(),
        'assignments' => [[
            'product_variant_id' => $missingStockVariant->getKey(),
            'quantity' => 2,
        ]],
    ]));
    expect($warnings[0]['available'])->toBe(0.0);

    $lotVariant = ProductVariant::factory()->expiryMaterial()->create();
    InventoryStock::factory()->for($lotVariant)->for($warehouse)->create([
        'on_hand_quantity' => '4.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '4.000000',
    ]);
    $lot = InventoryLot::factory()->for($lotVariant, 'productVariant')->for($warehouse)->create([
        'lot_number' => 'NO-EXPIRY-COVERAGE',
        'on_hand_quantity' => '4.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    expect(cioCall($page, 'lotOptions', $lotVariant->getKey(), $warehouse->getKey()))
        ->toHaveKey($lot->getKey());

    $placeholder = cioGet([
        '../../warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $missingStockVariant->getKey(),
    ]);
    expect(cioCall($page, 'quantityPlaceholder', $placeholder))->toBe('No available stock.');
});
