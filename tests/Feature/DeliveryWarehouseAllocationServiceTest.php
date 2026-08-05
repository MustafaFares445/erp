<?php

declare(strict_types=1);

use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Orders\DeliveryWarehouseAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
