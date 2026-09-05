<?php

declare(strict_types=1);

use App\Data\Inventory\StockDamageData;
use App\Enums\StockCondition;
use App\Models\InventoryAdjustment;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryDamageService;
use App\Services\Inventory\InventoryLotReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps condition and lot invariants through damage adjustment and recovery', function (): void {
    $actor = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $warehouse = Warehouse::factory()->create();

    $stock = InventoryStock::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'on_hand_quantity' => '10.000000',
            'reserved_quantity' => '0.000000',
            'damaged_quantity' => '0.000000',
            'available_quantity' => '10.000000',
        ]);

    $lot = InventoryLot::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'on_hand_quantity' => '10.000000',
            'reserved_quantity' => '0.000000',
            'expires_at' => null,
        ]);

    $damage = app(InventoryDamageService::class);
    $reconciliation = app(InventoryLotReconciliationService::class);

    $stock = $damage->damage(
        $stock,
        new StockDamageData(5, 'Five units failed inspection', null, $lot->getKey()),
        $actor,
    );

    expect($reconciliation->inspect()['errors'])->toBe([]);

    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'stock_condition' => StockCondition::Damaged,
        'inventory_lot_id' => $lot->getKey(),
        'new_quantity' => '3.000000',
    ]);

    app(InventoryAdjustmentService::class)->confirm(
        $adjustment,
        User::factory()->create(),
    );

    expect($stock->fresh()?->on_hand_quantity)->toBe('8.000000')
        ->and($stock->fresh()?->damaged_quantity)->toBe('3.000000')
        ->and($reconciliation->inspect()['errors'])->toBe([]);

    $damage->recover(
        $stock->fresh(),
        new StockDamageData(3, 'All remaining damaged units repaired', null, $lot->getKey()),
        $actor,
    );

    expect($stock->fresh()?->on_hand_quantity)->toBe('8.000000')
        ->and($stock->fresh()?->damaged_quantity)->toBe('0.000000')
        ->and($stock->fresh()?->available_quantity)->toBe('8.000000')
        ->and($reconciliation->inspect()['errors'])->toBe([]);
});
