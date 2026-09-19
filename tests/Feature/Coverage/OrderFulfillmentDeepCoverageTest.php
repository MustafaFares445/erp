<?php

declare(strict_types=1);

use App\Data\Orders\OrderFulfillmentData;
use App\Enums\DeliveryDocument;
use App\Enums\InventoryPermission;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
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

function ofDeepService(): OrderFulfillmentService
{
    return app(OrderFulfillmentService::class);
}
function ofDeepActor(): User
{
    $permission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $role = Role::findOrCreate('coverage-order-fulfillment', 'web');
    $role->givePermissionTo($permission);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    return $actor;
}

function ofDeepOrder(ProductVariant $variant, float $quantity = 2.0): array
{
    $customer = CustomerProfile::factory()->create([
        'latitude' => '25.2048000',
        'longitude' => '55.2708000',
    ]);
    $order = Order::factory()->for($customer, 'customer')->create();
    OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => $quantity,
        'unit_id' => $variant->unit_id,
    ]);

    return [$order, $customer];
}
it('suggests existing order allocations for ordinary and serialized products', function (): void {
    $warehouse = Warehouse::factory()->create([
        'latitude' => '25.2100000',
        'longitude' => '55.2750000',
    ]);
    $plain = ProductVariant::factory()->create();
    ProductVariant::query()->whereKey($plain)->update(['track_serials' => false, 'track_batches' => false]);
    $plain->refresh();
    [$plainOrder] = ofDeepOrder($plain, 2);
    InventoryStock::factory()->for($plain)->for($warehouse)->create(['available_quantity' => '2.000']);

    $plainShipments = ofDeepService()->suggestForOrder($plainOrder);
    expect($plainShipments)->toHaveCount(1)
        ->and($plainShipments[0]['assignments'][0]['product_variant_id'])->toBe($plain->getKey());

    $serialized = ProductVariant::factory()->machine()->create();
    [$serialOrder] = ofDeepOrder($serialized, 2);
    InventoryStock::factory()->for($serialized)->for($warehouse)->create(['available_quantity' => '2.000']);
    $units = SerializedInventoryUnit::factory()->count(2)
        ->for($serialized, 'productVariant')->for($warehouse, 'warehouse')
        ->create(['status' => SerializedInventoryUnitStatus::Available]);

    $serialShipments = ofDeepService()->suggestForOrder($serialOrder);
    expect($serialShipments[0]['assignments'][0]['serialized_inventory_unit_ids'])
        ->toBe($units->pluck('id')->all());
});
it('splits batch-tracked suggestions across available lots', function (): void {
    $warehouse = Warehouse::factory()->create([
        'latitude' => '25.2100000',
        'longitude' => '55.2750000',
    ]);
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    [$order] = ofDeepOrder($variant, 4);
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '4.000']);
    $first = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '1.000',
        'reserved_quantity' => '0.000',
        'expires_at' => today()->addMonth(),
    ]);
    $second = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '3.000',
        'reserved_quantity' => '0.000',
        'expires_at' => today()->addMonths(2),
    ]);

    $assignments = ofDeepService()->suggestForOrder($order)[0]['assignments'];
    expect($assignments)->toHaveCount(2)
        ->and(array_column($assignments, 'inventory_lot_id'))->toBe([$first->getKey(), $second->getKey()])
        ->and(array_sum(array_column($assignments, 'quantity')))->toBe(4.0);
});
it('rejects invalid existing-order suggestion states', function (): void {
    $service = ofDeepService();
    $variant = ProductVariant::factory()->machine()->create();
    [$order] = ofDeepOrder($variant, 2);
    $order->setRelation('customer', null);
    expect(fn (): array => $service->suggestForOrder($order))
        ->toThrow(ValidationException::class, 'customer is unavailable');

    $warehouse = Warehouse::factory()->create([
        'latitude' => '25.2100000',
        'longitude' => '55.2750000',
    ]);
    [$fractionalOrder] = ofDeepOrder($variant, 2);
    OrderLine::query()->where('order_id', $fractionalOrder->getKey())->update(['base_quantity' => '1.500000']);
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '3.500']);
    expect(fn (): array => $service->suggestForOrder($fractionalOrder))
        ->toThrow(ValidationException::class, 'whole-number base quantity');

    [$shortSerialOrder] = ofDeepOrder($variant, 2);
    SerializedInventoryUnit::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    expect(fn (): array => $service->suggestForOrder($shortSerialOrder))
        ->toThrow(ValidationException::class, 'not enough available serial numbers');
});
it('rejects a batch suggestion when lot balances no longer cover stock demand', function (): void {
    $warehouse = Warehouse::factory()->create([
        'latitude' => '25.2100000',
        'longitude' => '55.2750000',
    ]);
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    [$order] = ofDeepOrder($variant, 4);
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '4.000']);
    InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '1.000',
        'reserved_quantity' => '0.000',
        'expires_at' => today()->addMonth(),
    ]);

    expect(fn (): array => ofDeepService()->suggestForOrder($order))
        ->toThrow(ValidationException::class, 'lot quantities no longer cover');
});

it('rejects prepareExisting when the fulfillment customer does not match', function (): void {
    $variant = ProductVariant::factory()->create();
    [$order] = ofDeepOrder($variant, 1);
    $fulfillment = new OrderFulfillmentData(
        CustomerProfile::factory()->create(), [], [], ofDeepActor(), null,
    );

    expect(fn (): Order => ofDeepService()->prepareExisting($order, $fulfillment))
        ->toThrow(ValidationException::class, 'sales order customer');
});
it('rejects prepareExisting when fulfillment records already exist', function (): void {
    $variant = ProductVariant::factory()->create();
    [$order, $customer] = ofDeepOrder($variant, 1);
    Shipment::factory()->for($order)->create();
    $fulfillment = new OrderFulfillmentData(
        $customer,
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        [['warehouse_id' => Warehouse::factory()->create()->getKey(), 'assignments' => []]],
        ofDeepActor(),
        null,
    );

    expect(fn (): Order => ofDeepService()->prepareExisting($order, $fulfillment))
        ->toThrow(ValidationException::class, 'already has fulfillment records');
});

it('prepares an existing commercial order without creating a second order', function (): void {
    $actor = ofDeepActor();
    $variant = ProductVariant::factory()->create();
    ProductVariant::query()->whereKey($variant)->update(['track_serials' => false, 'track_batches' => false]);
    $variant->refresh();
    [$order, $customer] = ofDeepOrder($variant, 2);
    $warehouse = Warehouse::factory()->create([
        'latitude' => '25.2100000',
        'longitude' => '55.2750000',
    ]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '2.000']);
    $service = ofDeepService();
    $shipments = $service->suggestForOrder($order);
    $fulfillment = new OrderFulfillmentData(
        customer: $customer,
        products: $service->productsForOrder($order),
        shipments: $shipments,
        actor: $actor,
        notes: 'Prepared existing order',
    );
    $prepared = $service->prepareExisting($order, $fulfillment);

    expect(Order::query()->count())->toBe(1)
        ->and($prepared->getKey())->toBe($order->getKey())
        ->and($prepared->status->value)->toBe('released')
        ->and($prepared->deliveries)->toHaveCount(1)
        ->and($prepared->shipments)->toHaveCount(1);
});

it('covers the remaining batch suggestion loop branches', function (): void {
    $warehouse = Warehouse::factory()->create([
        'latitude' => '25.2100000',
        'longitude' => '55.2750000',
    ]);
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    [$order] = ofDeepOrder($variant, 2);
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '2.000']);
    InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000',
        'reserved_quantity' => '0.000',
        'expires_at' => today()->addDay(),
    ]);
    InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '1.000',
        'reserved_quantity' => '0.000',
        'expires_at' => today()->addDays(2),
    ]);

    expect(ofDeepService()->suggestForOrder($order)[0]['assignments'])->toHaveCount(1);
});
it('skips a sub-tolerance available batch and then rejects the uncovered demand', function (): void {
    $warehouse = Warehouse::factory()->create([
        'latitude' => '25.2100000',
        'longitude' => '55.2750000',
    ]);
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    [$order] = ofDeepOrder($variant, 1);
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '1.000']);
    InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '0.000001',
        'reserved_quantity' => '0.000000',
        'expires_at' => today()->addDay(),
    ]);

    expect(fn (): array => ofDeepService()->suggestForOrder($order))
        ->toThrow(ValidationException::class, 'lot quantities no longer cover');
});

it('covers commercial base consumption skip and overflow guards', function (): void {
    $service = ofDeepService();
    $method = new ReflectionMethod($service, 'consumeCommercialBase');
    $remaining = [11 => 0.0, 12 => 1.0];
    $arguments = [[99 => [11, 12]], &$remaining, 99, 1.0];
    $splits = $method->invokeArgs($service, $arguments);

    expect($splits)->toBe([['order_line_id' => 12, 'base_quantity' => 1.0]]);
    $remaining = [21 => 0.25];
    $arguments = [[88 => [21]], &$remaining, 88, 1.0];

    expect(fn (): mixed => $method->invokeArgs($service, $arguments))
        ->toThrow(DomainException::class, 'exceeds the remaining commercial sales order quantity');
});

it('syncs fulfillment documents while creating a delivery', function (): void {
    Storage::fake('local');
    $actor = ofDeepActor();
    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    ProductVariant::query()->whereKey($variant)->update(['track_serials' => false, 'track_batches' => false]);
    $variant->refresh();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '1.000']);
    $path = UploadedFile::fake()
        ->create('payment-receipt.pdf', 10, 'application/pdf')
        ->store('delivery-documents/payment_receipt', 'local');

    expect($path)->toBeString();
    $order = ofDeepService()->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        shipments: [[
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ]],
        actor: $actor,
        notes: null,
        documents: [DeliveryDocument::PaymentReceipt->value => $path],
    ));

    $delivery = $order->deliveries()->sole();
    expect($delivery->getFirstMedia(DeliveryDocument::PaymentReceipt->value))->not->toBeNull();
});
