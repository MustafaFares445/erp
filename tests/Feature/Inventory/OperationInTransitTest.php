<?php

declare(strict_types=1);

use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inTransitService(): InventoryOperationService
{
    return app(InventoryOperationService::class);
}

// FR-007, SRS §3.12: in-transit quantity is counted against neither warehouse, and stays visible
// via InventoryStock::inTransitQuantity() re-pointed from "transfer status = dispatched" to
// "operation stage = in_transit" (data-model.md §9).

it('counts a dispatched transfer against neither warehouse balance, yet reports it as in transit', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $sourceStock = InventoryStock::factory()->for($variant)->for($source)->create(['on_hand_quantity' => '10.000', 'available_quantity' => '10.000']);
    $operation = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '6.000', 'unit_id' => $variant->unit_id]);
    $actor = User::factory()->create();

    inTransitService()->markReady($operation->refresh());
    inTransitService()->dispatch($operation->refresh(), $actor);

    expect((float) $sourceStock->refresh()->on_hand_quantity)->toBe(4.0)
        ->and(InventoryStock::query()->where('warehouse_id', $destination->getKey())->exists())->toBeFalse()
        ->and($sourceStock->refresh()->inTransitQuantity())->toBe(0.0);

    $destinationPlaceholder = InventoryStock::factory()->for($variant)->for($destination)->create([
        'on_hand_quantity' => '0.000',
        'available_quantity' => '0.000',
    ]);

    expect($destinationPlaceholder->inTransitQuantity())->toBe(6.0);

    inTransitService()->complete($operation->refresh(), $actor);

    expect($destinationPlaceholder->refresh()->inTransitQuantity())->toBe(0.0)
        ->and((float) $destinationPlaceholder->refresh()->on_hand_quantity)->toBe(6.0);
});

it('never reports in-transit quantity for a receipt or delivery, which have no InTransit stage', function (): void {
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($destination)->create(['on_hand_quantity' => '0.000', 'available_quantity' => '0.000']);
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $destination->getKey()]);
    $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '3.000', 'unit_id' => $variant->unit_id]);

    inTransitService()->markReady($operation);

    expect($stock->refresh()->inTransitQuantity())->toBe(0.0);
});

it('sums in-transit quantity across multiple dispatched transfers heading to the same destination', function (): void {
    $sourceA = Warehouse::factory()->create();
    $sourceB = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($sourceA)->create(['on_hand_quantity' => '10.000', 'available_quantity' => '10.000']);
    InventoryStock::factory()->for($variant)->for($sourceB)->create(['on_hand_quantity' => '10.000', 'available_quantity' => '10.000']);
    $actor = User::factory()->create();

    foreach ([$sourceA, $sourceB] as $source) {
        $operation = InventoryOperation::factory()->internalTransfer()->create([
            'source_warehouse_id' => $source->getKey(),
            'destination_warehouse_id' => $destination->getKey(),
        ]);
        $operation->lines()->create(['product_variant_id' => $variant->getKey(), 'quantity' => '2.000', 'unit_id' => $variant->unit_id]);
        inTransitService()->markReady($operation->refresh());
        inTransitService()->dispatch($operation->refresh(), $actor);
    }

    $destinationPlaceholder = InventoryStock::factory()->for($variant)->for($destination)->create([
        'on_hand_quantity' => '0.000',
        'available_quantity' => '0.000',
    ]);

    expect($destinationPlaceholder->inTransitQuantity())->toBe(4.0);
});
