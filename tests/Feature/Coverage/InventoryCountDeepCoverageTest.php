<?php

declare(strict_types=1);

use App\Data\Inventory\CountScopeData;
use App\Enums\CountScope;
use App\Enums\InventoryCountStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryAdjustment;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function countCoverageScope(
    Warehouse $warehouse,
    CountScope $scope,
    ?int $categoryId = null,
    ?int $lotId = null,
    ?array $variantIds = null,
    array $conditions = [],
): CountScopeData {
    return new CountScopeData(
        warehouseId: (int) $warehouse->getKey(),
        scopeType: $scope,
        productCategoryId: $categoryId,
        inventoryLotId: $lotId,
        productVariantIds: $variantIds,
        conditions: $conditions,
        materialityThresholdMinor: null,
    );
}

function countCoverageInvoke(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(InventoryCountService::class, $method)
        ->invoke(app(InventoryCountService::class), ...$arguments);
}
it('covers physical count scope validation guards', function (): void {
    $service = app(InventoryCountService::class);
    $actor = User::factory()->create();
    $inactive = Warehouse::factory()->create(['is_active' => false]);
    $warehouse = Warehouse::factory()->create();

    expect(fn () => $service->open(
        countCoverageScope($inactive, CountScope::Warehouse),
        $actor,
    ))->toThrow(DomainException::class, 'inactive warehouse');

    expect(fn () => $service->open(
        countCoverageScope($warehouse, CountScope::Category),
        $actor,
    ))->toThrow(DomainException::class, 'requires a product category');

    expect(fn () => $service->open(
        countCoverageScope($warehouse, CountScope::Lot),
        $actor,
    ))->toThrow(DomainException::class, 'requires a lot');

    expect(fn () => $service->open(
        countCoverageScope($warehouse, CountScope::VariantSet, variantIds: []),
        $actor,
    ))->toThrow(DomainException::class, 'requires at least one product variant');

    expect(fn () => $service->open(
        countCoverageScope($warehouse, CountScope::Warehouse),
        $actor,
    ))->toThrow(DomainException::class, 'produced no lines');
});
it('covers condition parsing and identifier helper guards', function (): void {
    expect(countCoverageInvoke('resolveConditions', []))
        ->toBe([StockCondition::Saleable, StockCondition::Quarantine, StockCondition::Damaged])
        ->and(fn (): mixed => countCoverageInvoke('resolveConditions', [123]))
        ->toThrow(DomainException::class, 'must be strings')
        ->and(fn (): mixed => countCoverageInvoke('resolveConditions', [StockCondition::Disposed->value]))
        ->toThrow(DomainException::class, 'not a valid materialized stock condition')
        ->and(countCoverageInvoke('idList', new Collection([1, '2', 'bad', 1, null])))
        ->toBe([1, 2])
        ->and(fn (): mixed => countCoverageInvoke('integerKey', new stdClass, 'coverage object'))
        ->toThrow(LogicException::class, 'must be an Eloquent model')
        ->and(fn (): mixed => countCoverageInvoke('integerKey', new InventoryCount, 'coverage count'))
        ->toThrow(LogicException::class, 'identifiers must be integers');
});
it('covers lot scoped variant resolution', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $lot = InventoryLot::factory()->canonical()->create([
        'product_variant_id' => $variant->getKey(),
    ]);
    $warehouse = Warehouse::factory()->create();

    $data = countCoverageScope(
        $warehouse,
        CountScope::Lot,
        lotId: (int) $lot->getKey(),
    );

    expect(countCoverageInvoke('lotVariantId', $data))->toBe($variant->getKey())
        ->and(countCoverageInvoke('scopedVariantIds', $data))->toBe([$variant->getKey()]);
});
it('rejects recording a count on a closed document and covers recount success', function (): void {
    $actor = User::factory()->create();
    $closed = InventoryCount::factory()->create([
        'status' => InventoryCountStatus::Cancelled,
    ]);
    $closedLine = InventoryCountLine::factory()->create([
        'inventory_count_id' => $closed->getKey(),
    ]);

    expect(fn () => app(InventoryCountService::class)->recordCount($closedLine, '1', $actor))
        ->toThrow(DomainException::class, 'open for counting');

    $counting = InventoryCount::factory()->counting()->create();
    $line = InventoryCountLine::factory()->create([
        'inventory_count_id' => $counting->getKey(),
        'recount_requested' => false,
        'note' => null,
    ]);

    $updated = app(InventoryCountService::class)->requestRecount($line, $actor, 'Verify shelf quantity.');

    expect($updated->recount_requested)->toBeTrue()
        ->and($updated->note)->toBe('Verify shelf quantity.');
});
it('confirms a zero variance count without creating an adjustment', function (): void {
    $counter = User::factory()->create();
    $confirmer = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();

    InventoryLot::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'on_hand_quantity' => '4.000000',
            'reserved_quantity' => '0.000000',
        ]);

    $service = app(InventoryCountService::class);
    $count = $service->open(
        countCoverageScope(
            $warehouse,
            CountScope::VariantSet,
            variantIds: [(int) $variant->getKey()],
            conditions: [StockCondition::Saleable->value],
        ),
        $counter,
    );

    $line = $count->lines()->sole();
    $service->recordCount($line, '4.000000', $counter);
    $submitted = $service->submitForReview($count, $counter);
    $confirmed = $service->confirm($submitted, $confirmer);

    expect($confirmed->status)->toBe(InventoryCountStatus::Confirmed)
        ->and($confirmed->inventory_adjustment_id)->toBeNull()
        ->and(InventoryAdjustment::query()->count())->toBe(0);
});

it('confirms a partial review with uncounted lines and no variance adjustment', function (): void {
    $counter = User::factory()->create();
    $confirmer = User::factory()->create();
    $count = InventoryCount::factory()->pendingReview()->create([
        'counted_by' => $counter->getKey(),
        'is_partial' => true,
    ]);
    InventoryCountLine::factory()->create([
        'inventory_count_id' => $count->getKey(),
        'counted_base_quantity' => null,
        'variance_base_quantity' => null,
        'recount_requested' => false,
    ]);

    $confirmed = app(InventoryCountService::class)->confirm($count, $confirmer);

    expect($confirmed->status)->toBe(InventoryCountStatus::Confirmed)
        ->and($confirmed->inventory_adjustment_id)->toBeNull();
});

it('populates lot scoped serialized inventory only from the requested lot', function (): void {
    $actor = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();

    $lot = InventoryLot::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create();

    $included = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'inventory_lot_id' => $lot->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'stock_condition' => StockCondition::Saleable,
        'custody_type' => SerializedCustodyType::Warehouse,
    ]);

    SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'inventory_lot_id' => null,
        'status' => SerializedInventoryUnitStatus::Available,
        'stock_condition' => StockCondition::Saleable,
        'custody_type' => SerializedCustodyType::Warehouse,
    ]);

    $count = app(InventoryCountService::class)->open(
        countCoverageScope(
            $warehouse,
            CountScope::Lot,
            lotId: (int) $lot->getKey(),
            conditions: [StockCondition::Saleable->value],
        ),
        $actor,
    );

    expect($count->lines()->count())->toBe(1)
        ->and($count->lines()->sole()->serialized_inventory_unit_id)->toBe($included->getKey());
});

it('populates lot scoped batch balances only from the requested lot', function (): void {
    $actor = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();

    $lot = InventoryLot::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create();

    InventoryLotBalance::query()->firstOrNew([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable->value,
    ])->forceFill([
        'on_hand_base_quantity' => '3.500000',
        'reserved_base_quantity' => '0.000000',
    ])->save();

    $otherLot = InventoryLot::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create();

    InventoryLotBalance::query()->firstOrNew([
        'inventory_lot_id' => $otherLot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable->value,
    ])->forceFill([
        'on_hand_base_quantity' => '9.000000',
        'reserved_base_quantity' => '0.000000',
    ])->save();

    $count = app(InventoryCountService::class)->open(
        countCoverageScope(
            $warehouse,
            CountScope::Lot,
            lotId: (int) $lot->getKey(),
            conditions: [StockCondition::Saleable->value],
        ),
        $actor,
    );

    $line = $count->lines()->sole();
    expect($line->inventory_lot_id)->toBe($lot->getKey())
        ->and($line->system_base_quantity)->toBe('3.500000');
});

it('falls back to aggregate condition balances when the product relation is soft deleted', function (): void {
    $actor = User::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $product = $variant->product;

    expect($product)->not->toBeNull();
    $product->delete();

    InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '7.250000',
        'reserved_base_quantity' => '0.000000',
    ]);

    $count = app(InventoryCountService::class)->open(
        countCoverageScope(
            $warehouse,
            CountScope::Warehouse,
            conditions: [StockCondition::Saleable->value],
        ),
        $actor,
    );

    $line = $count->lines()->sole();
    expect($line->product_variant_id)->toBe($variant->getKey())
        ->and($line->inventory_lot_id)->toBeNull()
        ->and($line->serialized_inventory_unit_id)->toBeNull()
        ->and($line->system_base_quantity)->toBe('7.250000');
});
