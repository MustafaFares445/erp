<?php

declare(strict_types=1);

use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('can be created for a warehouse/variant pair with zero existing stock rows', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    $policy = WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
    ]);

    expect(WarehouseReplenishmentPolicy::query()->whereKey($policy->getKey())->exists())->toBeTrue()
        ->and(InventoryStock::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->exists())->toBeFalse();
});

it('rejects a duplicate warehouse/variant pair at the database level', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
    ]);

    expect(fn () => WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
    ]))->toThrow(QueryException::class);
});

it('rejects a max_quantity that is not above min_quantity on MySQL', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    expect(fn () => WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => 10,
        'max_quantity' => 10,
    ]))->toThrow(QueryException::class);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'The max-above-min check constraint is only enforced on MySQL.');

it('is breached when available stock has fallen to or below its minimum and the policy is active', function (): void {
    $stock = InventoryStock::factory()->create(['available_quantity' => 5]);
    $policy = WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $stock->warehouse_id,
        'product_variant_id' => $stock->product_variant_id,
        'min_quantity' => 5,
    ]);

    expect($policy->isBreachedBy($stock))->toBeTrue();
});

it('is not breached once available stock rises above the minimum', function (): void {
    $stock = InventoryStock::factory()->create(['available_quantity' => 6]);
    $policy = WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $stock->warehouse_id,
        'product_variant_id' => $stock->product_variant_id,
        'min_quantity' => 5,
    ]);

    expect($policy->isBreachedBy($stock))->toBeFalse();
});

it('is never breached while the policy is inactive, even at or below the minimum', function (): void {
    $stock = InventoryStock::factory()->create(['available_quantity' => 0]);
    $policy = WarehouseReplenishmentPolicy::factory()->inactive()->create([
        'warehouse_id' => $stock->warehouse_id,
        'product_variant_id' => $stock->product_variant_id,
        'min_quantity' => 5,
    ]);

    expect($policy->isBreachedBy($stock))->toBeFalse();
});
