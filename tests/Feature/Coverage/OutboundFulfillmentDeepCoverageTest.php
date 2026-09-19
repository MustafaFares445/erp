<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Logistics\OutboundFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function outboundFulfillmentCoverageInvoke(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(OutboundFulfillmentService::class, $method)
        ->invokeArgs(app(OutboundFulfillmentService::class), $arguments);
}

it('covers unavailable customer and unreleased order planning guards', function (): void {
    $detached = new Order;
    $detached->setRelation('customer', null);
    $detached->setRelation('lines', collect());

    expect(fn () => app(OutboundFulfillmentService::class)->suggest($detached))
        ->toThrow(DomainException::class, 'customer is unavailable');

    $order = Order::factory()->create(['status' => OrderStatus::Draft]);
    OrderLine::factory()->for($order)->create();

    expect(fn () => app(OutboundFulfillmentService::class)->plan(
        User::factory()->create(),
        $order,
        [],
    ))->toThrow(DomainException::class, 'Only a released customer order');
});

it('covers shipment normalization assignment guards', function (): void {
    expect(fn (): mixed => outboundFulfillmentCoverageInvoke('normalizeShipments', [[
        'warehouse_id' => 1,
        'assignments' => 'invalid',
    ]]))->toThrow(ValidationException::class, 'requires assignments')
        ->and(fn (): mixed => outboundFulfillmentCoverageInvoke('normalizeShipments', [[
            'warehouse_id' => 1,
            'assignments' => [['product_variant_id' => 1, 'quantity' => 0]],
        ]]))->toThrow(ValidationException::class, 'positive quantity')
        ->and(fn (): mixed => outboundFulfillmentCoverageInvoke('normalizeShipments', [[
            'warehouse_id' => 1,
            'assignments' => [],
        ]]))->toThrow(ValidationException::class, 'at least one product assignment');
});

it('covers consume skipping empty lines and exceeding remaining quantity', function (): void {
    $remaining = [10 => 0.0, 11 => 2.0];

    $splits = new ReflectionMethod(OutboundFulfillmentService::class, 'consume')
        ->invokeArgs(app(OutboundFulfillmentService::class), [
            [5 => [10, 11]],
            &$remaining,
            5,
            1.0,
        ]);

    expect($splits)->toBe([
        ['order_line_id' => 11, 'base_quantity' => 1.0],
    ])->and($remaining[11])->toBe(1.0);

    $remaining = [10 => 0.0];
    expect(fn (): mixed => new ReflectionMethod(OutboundFulfillmentService::class, 'consume')
        ->invokeArgs(app(OutboundFulfillmentService::class), [
            [5 => [10]],
            &$remaining,
            5,
            1.0,
        ]))->toThrow(DomainException::class, 'exceeded the remaining commercial line quantity');
});

it('rejects serialized planning when serial count does not match requested quantity', function (): void {
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $warehouse = Warehouse::factory()->create();
    $order = Order::factory()->create(['status' => OrderStatus::Released]);

    OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 2,
        'base_quantity' => 2,
        'short_closed_base_quantity' => 0,
        'unit_id' => $variant->unit_id,
    ]);

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'on_hand_quantity' => 2,
        'reserved_quantity' => 0,
        'available_quantity' => 2,
    ]);

    expect(fn () => app(OutboundFulfillmentService::class)->plan($actor, $order, [[
        'warehouse_id' => $warehouse->getKey(),
        'assignments' => [[
            'product_variant_id' => $variant->getKey(),
            'quantity' => 2,
            'inventory_lot_id' => null,
            'serialized_inventory_unit_ids' => [999999],
        ]],
    ]]))->toThrow(ValidationException::class, 'exactly one serial per unit');
});

it('creates delivery lines for a valid batch tracked assignment', function (): void {
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $warehouse = Warehouse::factory()->create();
    $lot = InventoryLot::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create(['on_hand_quantity' => '3.000000']);
    $order = Order::factory()->create(['status' => OrderStatus::Released]);

    $orderLine = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 3,
        'base_quantity' => 3,
        'short_closed_base_quantity' => 0,
        'unit_id' => $variant->unit_id,
    ]);

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'on_hand_quantity' => 3,
        'reserved_quantity' => 0,
        'available_quantity' => 3,
    ]);

    $planned = app(OutboundFulfillmentService::class)->plan($actor, $order, [[
        'warehouse_id' => $warehouse->getKey(),
        'assignments' => [[
            'product_variant_id' => $variant->getKey(),
            'quantity' => 2,
            'inventory_lot_id' => $lot->getKey(),
            'serialized_inventory_unit_ids' => [],
        ]],
    ]]);

    $deliveryLine = $planned->deliveries->first()->lines->sole();

    expect($deliveryLine->order_line_id)->toBe($orderLine->getKey())
        ->and((float) $deliveryLine->base_quantity)->toBe(2.0)
        ->and($deliveryLine->inventory_lot_id)->toBe($lot->getKey());
});

it('rejects a batch tracked assignment without a lot', function (): void {
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
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

    expect(fn () => app(OutboundFulfillmentService::class)->plan($actor, $order, [[
        'warehouse_id' => $warehouse->getKey(),
        'assignments' => [[
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
            'inventory_lot_id' => null,
            'serialized_inventory_unit_ids' => [],
        ]],
    ]]))->toThrow(ValidationException::class, 'requires a lot assignment');
});
