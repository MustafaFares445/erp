<?php

declare(strict_types=1);

use App\Enums\StockCondition;
use App\Filament\Widgets\DamagedStockQueue;
use App\Models\InventoryConditionBalance;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * WP-3.3 (GAP-UI-06, IN-07) — the damaged-stock work queue counts every
 * warehouse/variant grain currently holding damaged quantity, and only
 * those grains.
 */
it('counts and sums only currently damaged stock', function (): void {
    $warehouse = Warehouse::factory()->create();
    $damagedVariant = ProductVariant::factory()->create();
    $emptyDamagedVariant = ProductVariant::factory()->create();
    $saleableVariant = ProductVariant::factory()->create();

    InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => $damagedVariant->getKey(), 'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Damaged,
        'on_hand_base_quantity' => '4.000000', 'reserved_base_quantity' => '0.000000',
    ]);
    InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => $emptyDamagedVariant->getKey(), 'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Damaged,
        'on_hand_base_quantity' => '0.000000', 'reserved_base_quantity' => '0.000000',
    ]);
    InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => $saleableVariant->getKey(), 'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '9.000000', 'reserved_base_quantity' => '0.000000',
    ]);

    $widget = app(DamagedStockQueue::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats)->toHaveCount(1)
        ->and($stats[0]->getValue())->toBe('1')
        ->and($stats[0]->getDescription())->toBe('4.000000 base units currently damaged')
        ->and($stats[0]->getColor())->toBe('danger');
});

it('shows a success state when nothing is currently damaged', function (): void {
    $widget = app(DamagedStockQueue::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats[0]->getValue())->toBe('0')
        ->and($stats[0]->getColor())->toBe('success');
});
