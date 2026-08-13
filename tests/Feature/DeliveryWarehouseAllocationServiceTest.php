<?php

declare(strict_types=1);

use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Orders\DeliveryWarehouseAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function allocationService(): DeliveryWarehouseAllocationService
{
    return app(DeliveryWarehouseAllocationService::class);
}

it('selects the warehouse that completely covers more requested lines', function (): void {
    $completeWarehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $nearbyWarehouse = Warehouse::factory()->create(['latitude' => 25.2050, 'longitude' => 55.2700]);
    $firstVariant = ProductVariant::factory()->create();
    $secondVariant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($firstVariant)->for($completeWarehouse)->create(['available_quantity' => '10.000']);
    InventoryStock::factory()->for($secondVariant)->for($completeWarehouse)->create(['available_quantity' => '10.000']);
    InventoryStock::factory()->for($firstVariant)->for($nearbyWarehouse)->create(['available_quantity' => '10.000']);

    $shipments = app(DeliveryWarehouseAllocationService::class)->allocate(
        25.2048,
        55.2708,
        [
            ['product_variant_id' => $firstVariant->getKey(), 'quantity' => 2],
            ['product_variant_id' => $secondVariant->getKey(), 'quantity' => 3],
        ],
    );

    expect($shipments)->toHaveCount(1)
        ->and($shipments[0]['warehouse_id'])->toBe($completeWarehouse->getKey())
        ->and($shipments[0]['assignments'])->toHaveCount(2);
});

it('excludes warehouses without delivery coordinates from automatic allocation', function (): void {
    $warehouseWithoutCoordinates = Warehouse::factory()->create(['latitude' => null, 'longitude' => null]);
    $eligibleWarehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouseWithoutCoordinates)->create(['available_quantity' => '10.000']);
    InventoryStock::factory()->for($variant)->for($eligibleWarehouse)->create(['available_quantity' => '10.000']);

    $shipments = app(DeliveryWarehouseAllocationService::class)->allocate(
        25.2048,
        55.2708,
        [['product_variant_id' => $variant->getKey(), 'quantity' => 2]],
    );

    expect($shipments)->toHaveCount(1)
        ->and($shipments[0]['warehouse_id'])->toBe($eligibleWarehouse->getKey());
});

it('splits a single product across two warehouses when neither alone can fulfil it', function (): void {
    $firstWarehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $secondWarehouse = Warehouse::factory()->create(['latitude' => 25.2050, 'longitude' => 55.2700]);
    $variantA = ProductVariant::factory()->create();
    $variantB = ProductVariant::factory()->create();

    InventoryStock::factory()->for($variantA)->for($firstWarehouse)->create(['available_quantity' => '5.000']);
    InventoryStock::factory()->for($variantA)->for($secondWarehouse)->create(['available_quantity' => '5.000']);
    InventoryStock::factory()->for($variantB)->for($secondWarehouse)->create(['available_quantity' => '10.000']);

    $shipments = allocationService()->allocate(
        25.2048,
        55.2708,
        [
            ['product_variant_id' => $variantA->getKey(), 'quantity' => 10],
            ['product_variant_id' => $variantB->getKey(), 'quantity' => 10],
        ],
    );

    $assignmentsByWarehouse = collect($shipments)->keyBy('warehouse_id');
    $firstAssignments = collect($assignmentsByWarehouse->get($firstWarehouse->getKey())['assignments']);
    $secondAssignments = collect($assignmentsByWarehouse->get($secondWarehouse->getKey())['assignments']);

    expect($shipments)->toHaveCount(2)
        ->and((float) $firstAssignments->firstWhere('product_variant_id', $variantA->getKey())['quantity'])->toBe(5.0)
        ->and((float) $secondAssignments->firstWhere('product_variant_id', $variantA->getKey())['quantity'])->toBe(5.0)
        ->and((float) $secondAssignments->firstWhere('product_variant_id', $variantB->getKey())['quantity'])->toBe(10.0);
});

it('rejects requested lines that are not valid arrays', function (): void {
    expect(fn (): array => allocationService()->allocate(25.2048, 55.2708, ['not-an-array']))
        ->toThrow(ValidationException::class, 'Each selected product needs a valid quantity.');
});

it('rejects a requested line missing a variant id or quantity', function (): void {
    $variant = ProductVariant::factory()->create();

    expect(fn (): array => allocationService()->allocate(25.2048, 55.2708, [
        ['product_variant_id' => $variant->getKey(), 'quantity' => 0],
    ]))->toThrow(ValidationException::class, 'Each selected product needs a valid quantity.');
});

it('rejects duplicate product variants within the requested lines', function (): void {
    $variant = ProductVariant::factory()->create();

    expect(fn (): array => allocationService()->allocate(25.2048, 55.2708, [
        ['product_variant_id' => $variant->getKey(), 'quantity' => 1],
        ['product_variant_id' => $variant->getKey(), 'quantity' => 2],
    ]))->toThrow(ValidationException::class, 'Each product variant may only be requested once.');
});

it('rejects an empty list of requested products', function (): void {
    expect(fn (): array => allocationService()->allocate(25.2048, 55.2708, []))
        ->toThrow(ValidationException::class, 'Select at least one product before continuing.');
});

it('accepts numeric-string product variant ids and quantities', function (): void {
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);

    $shipments = allocationService()->allocate(25.2048, 55.2708, [
        ['product_variant_id' => (string) $variant->getKey(), 'quantity' => '3'],
    ]);

    expect($shipments)->toHaveCount(1)
        ->and($shipments[0]['assignments'][0]['quantity'])->toBe(3.0);
});

it('rejects allocation when total eligible stock cannot cover the requested quantity', function (): void {
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '2.000']);

    expect(fn (): array => allocationService()->allocate(25.2048, 55.2708, [
        ['product_variant_id' => $variant->getKey(), 'quantity' => 5],
    ]))->toThrow(ValidationException::class, 'There is not enough eligible warehouse stock to fulfil every requested product.');
});

it('rejects allocation when no single warehouse can fully complete any remaining product', function (): void {
    $firstWarehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $secondWarehouse = Warehouse::factory()->create(['latitude' => 25.2050, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($variant)->for($firstWarehouse)->create(['available_quantity' => '5.000']);
    InventoryStock::factory()->for($variant)->for($secondWarehouse)->create(['available_quantity' => '5.000']);

    expect(fn (): array => allocationService()->allocate(25.2048, 55.2708, [
        ['product_variant_id' => $variant->getKey(), 'quantity' => 10],
    ]))->toThrow(ValidationException::class, 'No eligible warehouse can fulfil the remaining requested stock.');
});

it('accepts a fully assigned and available shipment during validation', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);

    allocationService()->validate(
        [['product_variant_id' => $variant->getKey(), 'quantity' => 4]],
        [['warehouse_id' => $warehouse->getKey(), 'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 4]]]],
    );

    expect(true)->toBeTrue();
});

it('rejects validation when the assigned quantity does not match the demanded quantity', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '10.000']);

    expect(fn (): null => allocationService()->validate(
        [['product_variant_id' => $variant->getKey(), 'quantity' => 4]],
        [['warehouse_id' => $warehouse->getKey(), 'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 2]]]],
    ))->toThrow(ValidationException::class, 'Every selected product must be fully assigned, without exceeding its requested quantity.');
});

it('rejects validation when a warehouse no longer has enough available stock', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '2.000']);

    expect(fn (): null => allocationService()->validate(
        [['product_variant_id' => $variant->getKey(), 'quantity' => 4]],
        [['warehouse_id' => $warehouse->getKey(), 'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 4]]]],
    ))->toThrow(ValidationException::class, 'A warehouse no longer has enough available stock for this allocation.');
});

it('rejects a shipment that is not a valid array during validation', function (): void {
    $variant = ProductVariant::factory()->create();

    expect(fn (): null => allocationService()->validate(
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ['not-an-array'],
    ))->toThrow(ValidationException::class, 'Each warehouse allocation must be valid.');
});

it('rejects a shipment with no assigned products during validation', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    expect(fn (): null => allocationService()->validate(
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        [['warehouse_id' => $warehouse->getKey(), 'assignments' => []]],
    ))->toThrow(ValidationException::class, 'Remove warehouses that have no assigned products.');
});

it('rejects an assignment that is not a valid array during validation', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    expect(fn (): null => allocationService()->validate(
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        [['warehouse_id' => $warehouse->getKey(), 'assignments' => ['not-an-array']]],
    ))->toThrow(ValidationException::class, 'Each warehouse assignment needs a product and quantity.');
});

it('rejects an assignment missing a product or quantity during validation', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    expect(fn (): null => allocationService()->validate(
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        [['warehouse_id' => $warehouse->getKey(), 'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 0]]]],
    ))->toThrow(ValidationException::class, 'Each warehouse assignment needs a product and quantity.');
});

it('rejects a shipment naming a product that was not selected', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unselectedVariant = ProductVariant::factory()->create();

    expect(fn (): null => allocationService()->validate(
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        [['warehouse_id' => $warehouse->getKey(), 'assignments' => [['product_variant_id' => $unselectedVariant->getKey(), 'quantity' => 1]]]],
    ))->toThrow(ValidationException::class, 'A shipment contains a product that was not selected.');
});

it('rejects validation when no shipments assign the selected products', function (): void {
    $variant = ProductVariant::factory()->create();

    expect(fn (): null => allocationService()->validate(
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        [],
    ))->toThrow(ValidationException::class, 'Assign every selected product to a warehouse.');
});

it('rejects allocation when every candidate has no available stock', function (): void {
    $warehouse = Warehouse::factory()->create(['latitude' => 25.2100, 'longitude' => 55.2700]);
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['available_quantity' => '0.000']);

    expect(fn (): array => allocationService()->allocate(25.2048, 55.2708, [
        ['product_variant_id' => $variant->getKey(), 'quantity' => 1],
    ]))->toThrow(ValidationException::class);
});

it('handles zero allocations and empty selected warehouses defensively', function (): void {
    $service = allocationService();
    $warehouse = Warehouse::factory()->make(['id' => 1]);
    $reflection = new ReflectionMethod($service, 'distributeDemand');

    expect($reflection->invoke($service, [1 => 2.0], [
        1 => ['warehouse' => $warehouse, 'stocks' => [1 => 0.0]],
        2 => ['warehouse' => $warehouse, 'stocks' => [1 => 1.0]],
    ], [1, 2]))->toHaveCount(1)
        ->and($reflection->invoke($service, [], [1 => ['warehouse' => $warehouse, 'stocks' => []]], [1]))->toBe([]);

    $integer = new ReflectionMethod($service, 'integer');
    $positiveFloat = new ReflectionMethod($service, 'positiveFloat');

    expect($integer->invoke($service, 'not-numeric'))->toBeNull()
        ->and($positiveFloat->invoke($service, 'not-numeric'))->toBeNull();
});
