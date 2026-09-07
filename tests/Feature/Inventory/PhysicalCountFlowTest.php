<?php

declare(strict_types=1);

use App\Data\Inventory\CountScopeData;
use App\Enums\CountScope;
use App\Enums\InventoryCountStatus;
use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryCountService;
use App\Services\Inventory\InventoryLotReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * F-16: a physical count spanning multiple conditions and lots confirms
 * cleanly through the existing {@see InventoryAdjustmentService}
 * posting path, and canonical reconciliation stays clean afterwards.
 */
it('counts stock across three conditions and two lots, confirms the one divergent grain, and reconciles cleanly', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->expiryMaterial()->create();

    $lotA = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create(['on_hand_quantity' => '10.000000']);
    $lotB = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create(['on_hand_quantity' => '5.000000']);

    foreach ([$lotA, $lotB] as $lot) {
        foreach ([StockCondition::Quarantine, StockCondition::Damaged] as $condition) {
            InventoryLotBalance::query()->firstOrNew([
                'inventory_lot_id' => $lot->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'stock_condition' => $condition->value,
            ])->forceFill([
                'on_hand_base_quantity' => '0.000000',
                'reserved_base_quantity' => '0.000000',
            ])->save();
        }
    }

    foreach ([
        [StockCondition::Saleable, '15.000000'],
        [StockCondition::Quarantine, '0.000000'],
        [StockCondition::Damaged, '0.000000'],
    ] as [$condition, $quantity]) {
        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition,
            'on_hand_base_quantity' => $quantity,
            'reserved_base_quantity' => '0.000000',
        ]);
    }

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '15.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '15.000000',
    ]);

    $counter = User::factory()->create();
    $confirmer = User::factory()->create();
    $service = app(InventoryCountService::class);

    $count = $service->open(new CountScopeData(
        warehouseId: $warehouse->getKey(),
        scopeType: CountScope::Warehouse,
        productCategoryId: null,
        inventoryLotId: null,
        productVariantIds: null,
        conditions: [
            StockCondition::Saleable->value,
            StockCondition::Quarantine->value,
            StockCondition::Damaged->value,
        ],
        materialityThresholdMinor: null,
    ), $counter);

    // Two lots x three conditions = six grains.
    expect($count->lines()->count())->toBe(6);

    foreach ($count->lines as $line) {
        $isDivergentGrain = (int) $line->inventory_lot_id === $lotA->getKey()
            && $line->stock_condition === StockCondition::Saleable;

        $counted = $isDivergentGrain ? '9.000000' : (string) $line->system_base_quantity;

        $service->recordCount($line, $counted, $counter);
    }

    $submitted = $service->submitForReview($count, $counter);
    $confirmed = $service->confirm($submitted, $confirmer);

    expect($confirmed->status)->toBe(InventoryCountStatus::Confirmed)
        ->and($confirmed->inventory_adjustment_id)->not->toBeNull();

    expect(InventoryLotBalance::query()
        ->where('inventory_lot_id', $lotA->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->where('stock_condition', StockCondition::Saleable->value)
        ->value('on_hand_base_quantity'))->toBe('9.000000')
        ->and(InventoryLotBalance::query()
            ->where('inventory_lot_id', $lotB->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_condition', StockCondition::Saleable->value)
            ->value('on_hand_base_quantity'))->toBe('5.000000');

    $report = app(InventoryLotReconciliationService::class)->inspect();

    expect($report['errors'])->toBe([]);
});
