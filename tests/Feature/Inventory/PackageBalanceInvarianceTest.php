<?php

declare(strict_types=1);

use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('assigning a package to an operation line does not change stock balances', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $stock = InventoryStock::factory()->for($warehouse)->for($variant)->create([
        'on_hand_quantity' => '17.000',
        'reserved_quantity' => '2.000',
        'available_quantity' => '15.000',
    ]);
    $package = Package::factory()->for($warehouse)->create();
    $operation = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $warehouse->getKey()]);
    $line = $operation->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '3.000',
        'unit_id' => $variant->unit_id,
    ]);

    $line->update(['package_id' => $package->getKey()]);

    expect($line->refresh()->package_id)->toBe($package->getKey())
        ->and($stock->refresh()->on_hand_quantity)->toBe('17.000000')
        ->and($stock->reserved_quantity)->toBe('2.000000')
        ->and($stock->available_quantity)->toBe('15.000000');
});
