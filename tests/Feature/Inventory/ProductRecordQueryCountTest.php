<?php

declare(strict_types=1);

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('product quantities and movement lines do not issue a query per row', function (): void {
    $product = Product::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $package = Package::factory()->for($warehouse)->create();

    foreach (range(1, 3) as $index) {
        $variant = ProductVariant::factory()->for($product)->create();
        InventoryStock::factory()->for($variant)->for($warehouse)->create();
        InventoryMovement::factory()->for($variant)->for($warehouse)->create(['package_id' => $package->getKey()]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $stocks = $product->stocks()->addSelect(['in_transit_quantity' => InventoryStock::inTransitQuantitySubquery()])->with([
        'productVariant:id,sku,name',
        'warehouse:id,code,name',
    ])->get();
    $stocks->each(static fn (InventoryStock $stock): float => $stock->inTransitQuantity());

    $movements = $product->movements()->with([
        'productVariant:id,sku,name',
        'warehouse:id,code,name',
        'location:id,name',
        'package:id,name',
        'createdBy:id,name',
    ])->get();
    $movements->each(static fn (InventoryMovement $movement): ?string => $movement->package?->name);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(8);
});
