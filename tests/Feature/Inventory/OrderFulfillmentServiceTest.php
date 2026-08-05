<?php

declare(strict_types=1);

use App\Data\Orders\OrderFulfillmentData;
use App\Enums\DeliveryType;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderFulfillmentService;
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

    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
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

    $order = orderFulfillmentService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [
            ['product_variant_id' => $firstVariant->getKey(), 'quantity' => 3],
            ['product_variant_id' => $secondVariant->getKey(), 'quantity' => 4],
        ],
        shipments: [
            ['warehouse_id' => $firstWarehouse->getKey(), 'assignments' => [['product_variant_id' => $firstVariant->getKey(), 'quantity' => 3]], 'delivery_type' => DeliveryType::Outer->value],
            ['warehouse_id' => $secondWarehouse->getKey(), 'assignments' => [['product_variant_id' => $secondVariant->getKey(), 'quantity' => 4]], 'delivery_type' => DeliveryType::Inner->value],
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
        ->and(InventoryStock::query()->whereBelongsTo($firstWarehouse)->whereBelongsTo($firstVariant)->value('reserved_quantity'))->toBe('3.000')
        ->and(InventoryStock::query()->whereBelongsTo($secondWarehouse)->whereBelongsTo($secondVariant)->value('reserved_quantity'))->toBe('4.000');
});

it('persists tracking and attachments on each shipment instead of the delivery', function (): void {
    Storage::fake('local');
    $actor = orderCreator();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);
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
            'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
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
