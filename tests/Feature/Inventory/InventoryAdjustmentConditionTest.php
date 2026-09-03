<?php

declare(strict_types=1);

use App\Enums\StockCondition;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryAdjustment;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0:ProductVariant,1:Warehouse,2:InventoryStock,3:InventoryLot}
 */
function conditionAdjustmentFixture(
    StockCondition $condition,
    string $quantity,
): array {
    $variant = ProductVariant::factory()->grain()->create();
    $warehouse = Warehouse::factory()->create();

    $stock = InventoryStock::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'on_hand_quantity' => $quantity,
            'reserved_quantity' => '0.000000',
            'damaged_quantity' => $condition === StockCondition::Damaged ? $quantity : '0.000000',
            'available_quantity' => $condition === StockCondition::Saleable ? $quantity : '0.000000',
        ]);

    foreach ([
        StockCondition::Saleable,
        StockCondition::Quarantine,
        StockCondition::Damaged,
    ] as $materialized) {
        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $materialized,
            'on_hand_base_quantity' => $materialized === $condition ? $quantity : '0.000000',
            'reserved_base_quantity' => '0.000000',
        ]);
    }

    $lot = InventoryLot::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'on_hand_quantity' => $quantity,
            'reserved_quantity' => '0.000000',
        ]);

    foreach ([
        StockCondition::Saleable,
        StockCondition::Quarantine,
        StockCondition::Damaged,
    ] as $materialized) {
        InventoryLotBalance::query()->forceCreate([
            'inventory_lot_id' => $lot->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $materialized,
            'on_hand_base_quantity' => $materialized === $condition ? $quantity : '0.000000',
            'reserved_base_quantity' => '0.000000',
        ]);
    }

    return [$variant, $warehouse, $stock, $lot];
}

it('adjusts damaged quantity without mutating saleable stock', function (): void {
    [$variant, $warehouse, $stock, $lot] = conditionAdjustmentFixture(
        StockCondition::Damaged,
        '5.000000',
    );

    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'stock_condition' => StockCondition::Damaged,
        'inventory_lot_id' => $lot->getKey(),
        'new_quantity' => '3.000000',
    ]);

    app(InventoryAdjustmentService::class)->confirm($adjustment, User::factory()->create());

    expect($stock->fresh()?->on_hand_quantity)->toBe('3.000000')
        ->and($stock->fresh()?->damaged_quantity)->toBe('3.000000')
        ->and($stock->fresh()?->available_quantity)->toBe('0.000000')
        ->and(InventoryConditionBalance::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_condition', StockCondition::Saleable->value)
            ->value('on_hand_base_quantity'))->toBe('0.000000')
        ->and(InventoryConditionBalance::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_condition', StockCondition::Damaged->value)
            ->value('on_hand_base_quantity'))->toBe('3.000000');

    $movement = InventoryMovement::query()
        ->where('source_type', 'adjustment')
        ->where('source_id', $adjustment->getKey())
        ->sole();

    expect($movement->stock_condition_from)->toBe(StockCondition::Damaged)
        ->and($movement->stock_condition_to)->toBe(StockCondition::Damaged)
        ->and($movement->base_quantity_delta)->toBe('-2.000000');
});

it('adjusts quarantined quantity while keeping it unavailable for sale', function (): void {
    [$variant, $warehouse, $stock, $lot] = conditionAdjustmentFixture(
        StockCondition::Quarantine,
        '5.000000',
    );

    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'stock_condition' => StockCondition::Quarantine,
        'inventory_lot_id' => $lot->getKey(),
        'new_quantity' => '7.000000',
    ]);

    app(InventoryAdjustmentService::class)->confirm($adjustment, User::factory()->create());

    expect($stock->fresh()?->on_hand_quantity)->toBe('7.000000')
        ->and($stock->fresh()?->available_quantity)->toBe('0.000000')
        ->and($stock->fresh()?->damaged_quantity)->toBe('0.000000')
        ->and(InventoryConditionBalance::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_condition', StockCondition::Quarantine->value)
            ->value('on_hand_base_quantity'))->toBe('7.000000');
});

it('rejects disposed as an adjustment condition before posting anything', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'stock_condition' => StockCondition::Disposed,
        'new_quantity' => '0.000000',
    ]);

    expect(fn () => app(InventoryAdjustmentService::class)->confirm(
        $adjustment,
        User::factory()->create(),
    ))->toThrow(DomainException::class, 'Disposed stock cannot be adjusted');

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and($adjustment->fresh()?->isDraft())->toBeTrue();
});

it('still requires lot identity for a lot-tracked damaged count', function (): void {
    [$variant, $warehouse] = conditionAdjustmentFixture(
        StockCondition::Damaged,
        '2.000000',
    );

    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'stock_condition' => StockCondition::Damaged,
        'new_quantity' => '1.000000',
    ]);

    expect(fn () => app(InventoryAdjustmentService::class)->confirm(
        $adjustment,
        User::factory()->create(),
    ))->toThrow(DomainException::class, __('admin.inventory.lot.errors.required'));

    expect(InventoryMovement::query()->count())->toBe(0);
});

it('adjusts a serialized damaged unit and preserves its damaged condition evidence', function (): void {
    $variant = ProductVariant::factory()->machine()->create();
    $warehouse = Warehouse::factory()->create();

    $stock = InventoryStock::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'on_hand_quantity' => '1.000000',
            'reserved_quantity' => '0.000000',
            'damaged_quantity' => '1.000000',
            'available_quantity' => '0.000000',
        ]);

    foreach ([
        StockCondition::Saleable => '0.000000',
        StockCondition::Quarantine => '0.000000',
        StockCondition::Damaged => '1.000000',
    ] as $condition => $quantity) {
        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition,
            'on_hand_base_quantity' => $quantity,
            'reserved_base_quantity' => '0.000000',
        ]);
    }

    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Damaged,
        'stock_condition' => StockCondition::Damaged,
    ]);

    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create([
        'product_variant_id' => $variant->getKey(),
        'stock_condition' => StockCondition::Damaged,
        'serialized_inventory_unit_id' => $unit->getKey(),
        'new_quantity' => '0.000000',
    ]);

    app(InventoryAdjustmentService::class)->confirm($adjustment, User::factory()->create());

    expect($stock->fresh()?->on_hand_quantity)->toBe('0.000000')
        ->and($stock->fresh()?->damaged_quantity)->toBe('0.000000')
        ->and($unit->fresh()?->status)->toBe(SerializedInventoryUnitStatus::AdjustedOut)
        ->and($unit->fresh()?->stock_condition)->toBe(StockCondition::Damaged)
        ->and($unit->fresh()?->warehouse_id)->toBeNull();
});
