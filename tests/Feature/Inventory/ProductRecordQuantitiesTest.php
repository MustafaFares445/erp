<?php

declare(strict_types=1);

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a product quantities relationship is scoped to its variants and exposes every warehouse balance', function (): void {
    $product = Product::factory()->create();
    $firstVariant = ProductVariant::factory()->for($product)->create();
    $secondVariant = ProductVariant::factory()->for($product)->create();
    $otherVariant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    InventoryStock::factory()->for($firstVariant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '2.000',
        'available_quantity' => '8.000',
        'damaged_quantity' => '1.000',
    ]);
    InventoryStock::factory()->for($secondVariant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000',
        'reserved_quantity' => '1.000',
        'available_quantity' => '4.000',
        'damaged_quantity' => '0.000',
    ]);
    InventoryStock::factory()->for($otherVariant)->for($warehouse)->create();

    $stocks = $product->stocks()
        ->addSelect(['in_transit_quantity' => InventoryStock::inTransitQuantitySubquery()])
        ->get();

    expect($stocks)->toHaveCount(2)
        ->and((float) $stocks->sum('on_hand_quantity'))->toBe(15.0)
        ->and((float) $stocks->sum('reserved_quantity'))->toBe(3.0)
        ->and((float) $stocks->sum('available_quantity'))->toBe(12.0)
        ->and((float) $stocks->sum('damaged_quantity'))->toBe(1.0)
        ->and($stocks->every(fn (InventoryStock $stock): bool => $stock->inTransitQuantity() === 0.0))->toBeTrue();
});
