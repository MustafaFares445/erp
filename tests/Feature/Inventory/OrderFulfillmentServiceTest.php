<?php

declare(strict_types=1);

use App\Data\Orders\OrderFulfillmentData;
use App\Enums\AllocationSource;
use App\Enums\DeliveryType;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderFulfillmentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function orderFulfillmentService(): OrderFulfillmentService
{
    return app(OrderFulfillmentService::class);
}

function orderCreator(): User
{
    $permission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $role = Role::findOrCreate('order-delivery-creator', 'web');
    $role->givePermissionTo($permission);

    return tap(User::factory()->create(), fn (User $user): User => $user->assignRole($role));
}

it('prefers a single warehouse that covers more selected products', function (): void {
    $customer = CustomerProfile::factory()->create(['latitude' => '33.5138000', 'longitude' => '36.2765000']);
    $preferredWarehouse = Warehouse::factory()->create(['latitude' => '33.5200000', 'longitude' => '36.2800000']);
    $otherWarehouse = Warehouse::factory()->create(['latitude' => '33.5100000', 'longitude' => '36.2700000']);
    $firstVariant = ProductVariant::factory()->create();
    $secondVariant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($firstVariant)->for($preferredWarehouse)->create(['available_quantity' => '10.000']);
    InventoryStock::factory()->for($secondVariant)->for($preferredWarehouse)->create(['available_quantity' => '10.000']);
    InventoryStock::factory()->for($firstVariant)->for($otherWarehouse)->create(['available_quantity' => '10.000']);

    $shipments = orderFulfillmentService()->suggest($customer, [
        ['product_variant_id' => $firstVariant->getKey(), 'quantity' => 3],
        ['product_variant_id' => $secondVariant->getKey(), 'quantity' => 4],
    ]);

    expect($shipments)->toHaveCount(1)
        ->and($shipments[0]['warehouse_id'])->toBe($preferredWarehouse->getKey())
        ->and($shipments[0]['assignments'])->toHaveCount(2);
});

it('covers defensive allocation, parsing, and preview branches', function (): void {
    $service = orderFulfillmentService();
    $invoke = static function (string $method, array $arguments = []) use ($service): mixed {
        $reflection = new ReflectionMethod($service, $method);

        return $reflection->invokeArgs($service, $arguments);
    };

    expect(fn (): mixed => $invoke('requestedVariants', [[999999 => 1.0]]))
        ->toThrow(ValidationException::class);
    expect(fn (): mixed => $invoke('variant', [new Collection, 999999]))
        ->toThrow(DomainException::class);
    expect(fn (): mixed => $invoke('lockWarehouses', [[999999]]))
        ->toThrow(ValidationException::class);
    expect($invoke('shipmentInput', [[null, ['warehouse_id' => 99]], 100]))->toBe([])
        ->and($invoke('shipmentDeliveryType', [['delivery_type' => 'invalid']]))->toBe(DeliveryType::Inner)
        ->and($invoke('aggregateDeliveryType', [[null, ['delivery_type' => DeliveryType::Outer->value]]]))->toBe(DeliveryType::Outer)
        ->and($invoke('trackingNumber', [['tracking_number' => '  ']]))->toBeNull()
        ->and($invoke('trackingNumber', [['tracking_number' => 12]]))->toBeNull()
        ->and($invoke('attachments', [['attachments' => ['a.pdf', 12, null]]]))->toBe(['a.pdf']);

    $fulfillment = new OrderFulfillmentData(
        CustomerProfile::factory()->make(),
        [],
        [],
        User::factory()->make(),
        null,
    );

    expect($invoke('allocationSource', [
        $fulfillment,
        100,
        200,
    ]))->toBe(AllocationSource::Automatic);

    expect(fn (): mixed => $invoke('demands', [[null]]))->toThrow(ValidationException::class)
        ->and(fn (): mixed => $invoke('demands', [[]]))->toThrow(ValidationException::class);
    expect(fn (): mixed => $invoke('assignments', [[null], [1 => 1.0]]))->toThrow(ValidationException::class)
        ->and(fn (): mixed => $invoke('assignments', [[['warehouse_id' => 10], ['warehouse_id' => 10]], [1 => 1.0]]))->toThrow(ValidationException::class)
        ->and(fn (): mixed => $invoke('assignments', [[['warehouse_id' => 10, 'assignments' => [['product_variant_id' => 2, 'quantity' => 1]]]], [1 => 1.0]]))->toThrow(ValidationException::class)
        ->and(fn (): mixed => $invoke('assignments', [[], [1 => 1.0]]))->toThrow(ValidationException::class);

    expect($invoke('serialAssignments', [[null, ['warehouse_id' => 1, 'assignments' => 'invalid'], ['warehouse_id' => 1, 'assignments' => [['product_variant_id' => 2, 'serialized_inventory_unit_ids' => ['3', 'invalid']]]]]]))
        ->toBe([1 => [2 => [3]]]);
    expect($invoke('lotAssignments', [
        [
            null,
            ['warehouse_id' => 1, 'assignments' => 'invalid'],
            ['warehouse_id' => 1, 'assignments' => [['product_variant_id' => 2, 'inventory_lot_id' => '3', 'quantity' => '2']]],
        ],
    ]))
        ->toBe([
            1 => [
                2 => [
                    ['inventory_lot_id' => 3, 'quantity' => 2.0],
                ],
            ],
        ]);
    expect($invoke('previewAssignments', [['assignments' => [null, ['product_variant_id' => '2', 'quantity' => '1'], ['product_variant_id' => 2, 'quantity' => 0]]]]))
        ->toBe([['product_variant_id' => 2, 'quantity' => 1.0]]);
    expect(fn (): mixed => $invoke('shipmentAssignments', [['assignments' => 'invalid']]))->toThrow(ValidationException::class)
        ->and(fn (): mixed => $invoke('shipmentAssignments', [['assignments' => [null]]]))->toThrow(ValidationException::class)
        ->and(fn (): mixed => $invoke('shipmentAssignments', [['assignments' => []]]))->toThrow(ValidationException::class);

    expect(fn (): mixed => $invoke('assertAssignmentsMatchDemand', [[1 => 2.0], [10 => [1 => 1.0]]]))->toThrow(ValidationException::class)
        ->and(fn (): mixed => $invoke('assertStocksCanFulfill', [[10 => [1 => 1.0]], false]))->toThrow(ValidationException::class)
        ->and($invoke('assignmentVariantIds', [[null, ['assignments' => [['product_variant_id' => 1, 'quantity' => 1]]]]]))->toBe([1])
        ->and($invoke('distance', [null, 1, 2, 3]))->toBeNull()
        ->and($invoke('integer', ['invalid']))->toBeNull()
        ->and($invoke('positiveFloat', [0]))->toBeNull()
        ->and($invoke('integers', ['invalid']))->toBe([])
        ->and($invoke('coordinate', ['invalid']))->toBeNull();

    expect($service->availability(999999))->toMatchArray(['available_quantity' => 0.0, 'warehouses' => []]);

    expect(fn (): mixed => $service->suggest(CustomerProfile::factory()->make(['latitude' => null, 'longitude' => null]), []))
        ->toThrow(ValidationException::class);

    $warehouse = Warehouse::factory()->create(['latitude' => '25.2100', 'longitude' => '55.2700']);
    $customer = CustomerProfile::factory()->create(['latitude' => '25.2048', 'longitude' => '55.2708']);
    $fallbackVariant = ProductVariant::factory()->create(['name' => 'Fallback Variant', 'sku' => 'FALLBACK-1']);
    expect($service->routePreviews($customer, [
        null,
        ['warehouse_id' => null, 'assignments' => []],
        ['warehouse_id' => 999999, 'assignments' => []],
        ['warehouse_id' => $warehouse->getKey(), 'assignments' => [
            null,
            ['product_variant_id' => 999999, 'quantity' => 1],
            ['product_variant_id' => $fallbackVariant->getKey(), 'quantity' => 2],
        ]],
    ]))->toHaveCount(1);

    $fulfillment = new OrderFulfillmentData($customer, [], [], User::factory()->make(), null);
    expect($invoke('allocationSource', [$fulfillment, $warehouse->getKey(), 999999]))->toBe(AllocationSource::Automatic)
        ->and($invoke('allocationSource', [new OrderFulfillmentData(
            $customer,
            [],
            [['warehouse_id' => $warehouse->getKey(), 'assignments' => [null, ['product_variant_id' => 999999, 'allocation_source' => AllocationSource::Manual->value]]]],
            User::factory()->make(),
            null,
        ), $warehouse->getKey(), 999999]))->toBe(AllocationSource::Manual);

    $allocationSourceData = new OrderFulfillmentData(
        $customer,
        [],
        [
            null,
            ['warehouse_id' => $warehouse->getKey(), 'assignments' => 'invalid'],
            ['warehouse_id' => $warehouse->getKey(), 'assignments' => [
                null,
                ['product_variant_id' => 123456, 'allocation_source' => AllocationSource::Manual->value],
            ]],
        ],
        User::factory()->make(),
        null,
    );
    expect($invoke('allocationSource', [$allocationSourceData, $warehouse->getKey(), 999999]))->toBe(AllocationSource::Automatic);

    $malformedAssignments = [
        null,
        ['product_variant_id' => null, 'serialized_inventory_unit_ids' => [1]],
        ['product_variant_id' => 2, 'serialized_inventory_unit_ids' => []],
    ];
    $malformedShipments = [
        null,
        ['warehouse_id' => null],
        ['warehouse_id' => $warehouse->getKey(), 'assignments' => 'invalid'],
        ['warehouse_id' => $warehouse->getKey(), 'assignments' => $malformedAssignments],
    ];
    expect($invoke('serialAssignments', [$malformedShipments]))->toBe([]);

    $malformedLotAssignments = [
        null,
        ['product_variant_id' => null, 'inventory_lot_id' => 1, 'quantity' => 1],
        ['product_variant_id' => 2, 'inventory_lot_id' => null, 'quantity' => 1],
        ['product_variant_id' => 2, 'inventory_lot_id' => 1, 'quantity' => 0],
    ];
    $malformedLotShipments = [
        null,
        ['warehouse_id' => null],
        ['warehouse_id' => $warehouse->getKey(), 'assignments' => 'invalid'],
        ['warehouse_id' => $warehouse->getKey(), 'assignments' => $malformedLotAssignments],
    ];
    expect($invoke('lotAssignments', [$malformedLotShipments]))->toBe([])
        ->and($invoke('previewAssignments', [['assignments' => 'invalid']]))->toBe([])
        ->and($invoke('previewAssignments', [['assignments' => [null, ['product_variant_id' => null, 'quantity' => 1], ['product_variant_id' => 2, 'quantity' => 0]]]]))->toBe([]);

    expect(fn (): mixed => $invoke('demands', [[['product_variant_id' => 1, 'quantity' => 'invalid']]]))
        ->toThrow(ValidationException::class, 'Each selected product needs a valid quantity.')
        ->and(fn (): mixed => $invoke('assignments', [
            [
                ['warehouse_id' => $warehouse->getKey(), 'assignments' => [['product_variant_id' => 1, 'quantity' => 1]]],
                ['warehouse_id' => $warehouse->getKey(), 'assignments' => [['product_variant_id' => 1, 'quantity' => 1]]],
            ],
            [1 => 2.0],
        ]))->toThrow(ValidationException::class);

    $route = [
        'warehouse_latitude' => null,
        'warehouse_longitude' => null,
        'map_x' => null,
        'map_y' => null,
    ];
    expect($invoke('positionRoutes', [[$route], CustomerProfile::factory()->make(['latitude' => null, 'longitude' => null])]))->toBe([$route])
        ->and($invoke('positionRoutes', [[['warehouse_latitude' => 25.2048, 'warehouse_longitude' => 55.2708, 'map_x' => null, 'map_y' => null]], $customer]))->toHaveCount(1)
        ->and($invoke('positionRoutes', [[
            ['warehouse_latitude' => 25.2048, 'warehouse_longitude' => 55.2708, 'map_x' => null, 'map_y' => null],
            $route,
            ['warehouse_latitude' => 25.3, 'warehouse_longitude' => null, 'map_x' => null, 'map_y' => null],
            ['warehouse_latitude' => 25.4, 'warehouse_longitude' => 55.4, 'map_x' => null, 'map_y' => null],
        ], $customer]))->toHaveCount(4)
        ->and($invoke('integer', ['42']))->toBe(42)
        ->and($invoke('positiveFloat', ['0']))->toBeNull();
});

it('creates one ready delivery per assigned warehouse under one order', function (): void {
    $actor = orderCreator();
    $customer = CustomerProfile::factory()->create();
    $deliveryAddress = CustomerDeliveryAddress::factory()->for($customer, 'customer')->create([
        'address' => 'Delivery Street 1',
        'city' => 'Dubai',
        'is_default' => true,
    ]);
    $firstWarehouse = Warehouse::factory()->create();
    $secondWarehouse = Warehouse::factory()->create();
    $firstVariant = ProductVariant::factory()->create();
    $secondVariant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($firstVariant)->for($firstWarehouse)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'available_quantity' => '10.000']);
    InventoryStock::factory()->for($secondVariant)->for($secondWarehouse)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'available_quantity' => '10.000']);
    // The factory's default variant is a Grain, which — like an expiry material — is still
    // batch-tracked, so each outbound assignment below has to name the batch it draws from.
    $firstLot = InventoryLot::factory()->for($firstVariant, 'productVariant')->for($firstWarehouse)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'expires_at' => null]);
    $secondLot = InventoryLot::factory()->for($secondVariant, 'productVariant')->for($secondWarehouse)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'expires_at' => null]);

    $order = orderFulfillmentService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [
            ['product_variant_id' => $firstVariant->getKey(), 'quantity' => 3],
            ['product_variant_id' => $secondVariant->getKey(), 'quantity' => 4],
        ],
        shipments: [
            ['warehouse_id' => $firstWarehouse->getKey(), 'assignments' => [['product_variant_id' => $firstVariant->getKey(), 'quantity' => 3, 'inventory_lot_id' => $firstLot->getKey()]], 'delivery_type' => DeliveryType::Outer->value],
            ['warehouse_id' => $secondWarehouse->getKey(), 'assignments' => [['product_variant_id' => $secondVariant->getKey(), 'quantity' => 4, 'inventory_lot_id' => $secondLot->getKey()]], 'delivery_type' => DeliveryType::Inner->value],
        ],
        actor: $actor,
        notes: 'Split by warehouse',
        deliveryAddress: $deliveryAddress,
        scheduledAt: now()->addDay(),
        responsible: $actor,
    ));

    $deliveries = InventoryOperation::query()->whereMorphedTo('sourceDocument', $order)->orderBy('source_warehouse_id')->get();
    $shipments = Shipment::query()->whereBelongsTo($order)->orderBy('warehouse_id')->get();
    $firstDelivery = $deliveries->firstWhere('source_warehouse_id', $firstWarehouse->getKey());
    $secondDelivery = $deliveries->firstWhere('source_warehouse_id', $secondWarehouse->getKey());

    expect($order->lines)->toHaveCount(2)
        ->and($deliveries)->toHaveCount(2)
        ->and($deliveries->every(fn (InventoryOperation $delivery): bool => $delivery->customer_id === $customer->getKey()))->toBeTrue()
        ->and($deliveries->every(fn (InventoryOperation $delivery): bool => $delivery->stage === OperationStage::Ready))->toBeTrue()
        ->and($order->customer_delivery_address_id)->toBe($deliveryAddress->getKey())
        // The order aggregates to Outer because at least one of its shipments is Outer, even
        // though each delivery keeps its own shipment's delivery type.
        ->and($order->delivery_type)->toBe(DeliveryType::Outer->value)
        ->and($firstDelivery->delivery_type)->toBe(DeliveryType::Outer)
        ->and($secondDelivery->delivery_type)->toBe(DeliveryType::Inner)
        ->and($shipments)->toHaveCount(2)
        ->and($shipments->pluck('tracking_number')->every(fn (mixed $trackingNumber): bool => is_string($trackingNumber) && str_starts_with($trackingNumber, 'TRK-')))->toBeTrue()
        ->and($shipments->pluck('inventory_operation_id')->sort()->values()->all())->toBe($deliveries->pluck('id')->sort()->values()->all())
        ->and($deliveries->every(fn (InventoryOperation $delivery): bool => $delivery->customer_delivery_address_id === $deliveryAddress->getKey()))->toBeTrue()
        ->and($deliveries->every(fn (InventoryOperation $delivery): bool => $delivery->destination_address_snapshot !== null && $delivery->destination_address_snapshot['address'] === 'Delivery Street 1'))->toBeTrue()
        ->and(InventoryStock::query()->whereBelongsTo($firstWarehouse)->whereBelongsTo($firstVariant)->value('reserved_quantity'))->toBe('3.000000')
        ->and(InventoryStock::query()->whereBelongsTo($secondWarehouse)->whereBelongsTo($secondVariant)->value('reserved_quantity'))->toBe('4.000000');
});

it('rejects serial and batch metadata that conflicts with a product type', function (): void {
    $actor = orderCreator();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);

    expect(fn (): Order => orderFulfillmentService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        shipments: [[
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'serialized_inventory_unit_ids' => [999999]]],
        ]],
        actor: $actor,
        notes: null,
    )))->toThrow(ValidationException::class);
});

it('handles a legacy variant without tracking flags before readiness validation', function (): void {
    $actor = orderCreator();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    ProductVariant::query()->whereKey($variant)->update(['track_serials' => false, 'track_batches' => false]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);

    $order = orderFulfillmentService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        shipments: [[
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ]],
        actor: $actor,
        notes: null,
    ));

    expect($order)->toBeInstanceOf(Order::class);
});

it('rejects malformed fulfillment input rows before persistence', function (): void {
    $service = orderFulfillmentService();
    $invoke = (static fn (string $method, array $arguments = []): mixed => new ReflectionMethod($service, $method)->invokeArgs($service, $arguments));

    expect(fn (): mixed => $invoke('demands', [[['product_variant_id' => 1, 'quantity' => 'invalid']]]))
        ->toThrow(ValidationException::class, 'Each selected product needs a valid quantity.')
        ->and(fn (): mixed => $invoke('assignments', [[['warehouse_id' => null]], [1 => 1.0]]))
        ->toThrow(ValidationException::class)
        ->and($invoke('serialAssignments', [[['warehouse_id' => null]]]))->toBe([])
        ->and($invoke('lotAssignments', [[['warehouse_id' => null]]]))->toBe([]);
});

it('rejects batch metadata for a non-batch-tracked product', function (): void {
    $actor = orderCreator();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create([
        'track_serials' => false,
        'track_batches' => false,
    ]);
    ProductVariant::query()->whereKey($variant)->update(['track_serials' => false, 'track_batches' => false]);
    $variant->refresh();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '1.000']);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '1.000',
        'reserved_quantity' => '0.000',
        'expires_at' => null,
    ]);

    expect(fn (): Order => orderFulfillmentService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        shipments: [[
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 1,
                'inventory_lot_id' => $lot->getKey(),
            ]],
        ]],
        actor: $actor,
        notes: null,
    )))->toThrow(ValidationException::class, 'Batches may only be selected for batch-tracked products.');
});

it('rejects a delivery assignment whose warehouse disappeared before creation', function (): void {
    $service = orderFulfillmentService();
    $invoke = new ReflectionMethod($service, 'createDeliveries');
    $order = Order::factory()->create();
    $fulfillment = new OrderFulfillmentData($order->customer, [], [], orderCreator(), null);

    expect(fn (): mixed => $invoke->invoke(
        $service,
        $order,
        [999999 => [1 => 1.0]],
        new Collection,
        $fulfillment,
        [],
        [],
    ))->toThrow(DomainException::class);
});

it('persists tracking and attachments on each shipment instead of the delivery', function (): void {
    Storage::fake('local');
    $actor = orderCreator();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);
    // The factory's default variant is a Grain, which is batch-tracked, so the assignment
    // below has to name the batch it draws from.
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'expires_at' => null]);
    $path = UploadedFile::fake()->create('shipment-proof.pdf', 200, 'application/pdf')->store('shipment-attachments', 'local');

    if (! is_string($path)) {
        throw new RuntimeException('The fake shipment attachment could not be stored.');
    }

    $order = orderFulfillmentService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        shipments: [[
            'warehouse_id' => $warehouse->getKey(),
            'tracking_number' => 'CUSTOM-TRACKING-001',
            'attachments' => [$path],
            'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'inventory_lot_id' => $lot->getKey()]],
        ]],
        actor: $actor,
        notes: null,
    ));

    $shipment = $order->shipments()->sole();
    $delivery = $shipment->delivery()->firstOrFail();

    expect($shipment->tracking_number)->toBe('CUSTOM-TRACKING-001')
        ->and($shipment->getMedia('attachments'))->toHaveCount(1)
        ->and(array_key_exists('tracking_number', $delivery->getAttributes()))->toBeFalse()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('rolls back the order when assigned stock is no longer available', function (): void {
    $actor = orderCreator();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '2.000']);

    expect(fn (): Order => orderFulfillmentService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 3]],
        shipments: [['warehouse_id' => $warehouse->getKey(), 'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 3]]]],
        actor: $actor,
        notes: null,
    )))->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(0)
        ->and(InventoryOperation::query()->count())->toBe(0);
});

it('shows a warehouse route before products are assigned to its shipment', function (): void {
    $customer = CustomerProfile::factory()->create(['latitude' => '33.5138000', 'longitude' => '36.2765000']);
    $warehouse = Warehouse::factory()->create(['latitude' => '33.5200000', 'longitude' => '36.2800000']);

    $routes = orderFulfillmentService()->routePreviews($customer, [
        ['warehouse_id' => $warehouse->getKey(), 'assignments' => []],
    ]);

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['warehouse_name'])->toBe($warehouse->name)
        ->and($routes[0]['products'])->toBe([])
        ->and($routes[0]['distance_km'])->not->toBeNull();
});

it('creates one delivery line per selected serial for a serialized product', function (): void {
    $actor = orderCreator();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $devices = SerializedInventoryUnit::factory()->count(2)->for($variant, 'productVariant')->for($warehouse)->create([
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '2.000']);

    $order = orderFulfillmentService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 2]],
        shipments: [[
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 2,
                'serialized_inventory_unit_ids' => $devices->pluck('id')->all(),
            ]],
        ]],
        actor: $actor,
        notes: null,
    ));

    $delivery = InventoryOperation::query()->whereMorphedTo('sourceDocument', $order)->sole();

    expect($delivery->stage)->toBe(OperationStage::Ready)
        ->and($delivery->lines)->toHaveCount(2)
        ->and($delivery->lines->pluck('serialized_inventory_unit_id')->sort()->values()->all())
        ->toBe($devices->pluck('id')->sort()->values()->all())
        ->and($delivery->lines->pluck('quantity')->every(fn (mixed $quantity): bool => (float) $quantity === 1.0))->toBeTrue();
});

it('rejects a serialized delivery when the serial count does not match the quantity', function (): void {
    $actor = orderCreator();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $device = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '2.000']);

    expect(fn (): Order => orderFulfillmentService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 2]],
        shipments: [[
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 2,
                'serialized_inventory_unit_ids' => [$device->getKey()],
            ]],
        ]],
        actor: $actor,
        notes: null,
    )))->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(0);
});

it('rejects a serialized delivery naming a serial that is not available in that warehouse', function (): void {
    $actor = orderCreator();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $device = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($otherWarehouse, 'warehouse')->create([
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '1.000']);

    expect(fn (): Order => orderFulfillmentService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        shipments: [[
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 1,
                'serialized_inventory_unit_ids' => [$device->getKey()],
            ]],
        ]],
        actor: $actor,
        notes: null,
    )))->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(0);
});

it('creates a delivery line naming the batch for a batch-tracked product', function (): void {
    $actor = orderCreator();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'expires_at' => today()->addMonths(2),
    ]);

    $order = orderFulfillmentService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 4]],
        shipments: [[
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 4,
                'inventory_lot_id' => $lot->getKey(),
            ]],
        ]],
        actor: $actor,
        notes: null,
    ));

    $delivery = InventoryOperation::query()->whereMorphedTo('sourceDocument', $order)->sole();

    // Before batch selection was wired into this wizard, markReady() rejected every
    // batch-tracked delivery outright because no line ever named a lot to draw from.
    expect($delivery->stage)->toBe(OperationStage::Ready)
        ->and($delivery->lines)->toHaveCount(1)
        ->and((float) $delivery->lines->sole()->quantity)->toBe(4.0)
        ->and($delivery->lines->sole()->inventory_lot_id)->toBe($lot->getKey())
        ->and($lot->conditionReservedQuantity(StockCondition::Saleable, (int) $warehouse->getKey()))->toBe(4.0);
});

it('rejects a batch-tracked delivery when no batch is selected', function (): void {
    $actor = orderCreator();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);
    InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'expires_at' => today()->addMonths(2),
    ]);

    expect(fn (): Order => orderFulfillmentService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 4]],
        shipments: [[
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 4]],
        ]],
        actor: $actor,
        notes: null,
    )))->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(0);
});

it('rejects duplicate warehouse shipments', function (): void {
    $warehouse = Warehouse::factory()->create();
    $firstVariant = ProductVariant::factory()->create();
    $secondVariant = ProductVariant::factory()->create();

    expect(fn (): null => orderFulfillmentService()->validateFulfillment([
        ['product_variant_id' => $firstVariant->getKey(), 'quantity' => 1],
        ['product_variant_id' => $secondVariant->getKey(), 'quantity' => 1],
    ], [
        ['warehouse_id' => $warehouse->getKey(), 'assignments' => [['product_variant_id' => $firstVariant->getKey(), 'quantity' => 1]]],
        ['warehouse_id' => $warehouse->getKey(), 'assignments' => [['product_variant_id' => $secondVariant->getKey(), 'quantity' => 1]]],
    ]))->toThrow(ValidationException::class, 'Each selected warehouse may only have one shipment.');
});
