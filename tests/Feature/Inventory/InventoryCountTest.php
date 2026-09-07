<?php

declare(strict_types=1);

use App\Data\Inventory\CountScopeData;
use App\Enums\CountScope;
use App\Enums\InventoryCountStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Exceptions\Domain\SelfConfirmationRejected;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentItem;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Concerns\EnforcesMakerChecker;
use App\Services\Inventory\InventoryCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inventoryCountScope(
    Warehouse $warehouse,
    array $conditions,
    ?int $materialityThresholdMinor = null,
): CountScopeData {
    return new CountScopeData(
        warehouseId: $warehouse->getKey(),
        scopeType: CountScope::Warehouse,
        productCategoryId: null,
        inventoryLotId: null,
        productVariantIds: null,
        conditions: $conditions,
        materialityThresholdMinor: $materialityThresholdMinor,
    );
}

/**
 * A lot-tracked variant (every non-machine product type in this codebase
 * tracks batches) with one lot carrying the given per-condition quantities.
 * Mirrors the fixture pattern already used by
 * `tests/Feature/Inventory/InventoryAdjustmentConditionTest.php`: the
 * aggregate {@see InventoryConditionBalance}, the lot balance, and the
 * {@see InventoryStock} row must all exist before the adjustment posting
 * path this service delegates to can run.
 *
 * @param  array<string,string>  $lotQuantities  keyed by {@see StockCondition::value}
 * @return array{0:ProductVariant,1:Warehouse,2:InventoryLot}
 */
function lotTrackedCountFixture(?Warehouse $warehouse, array $lotQuantities): array
{
    $variant = ProductVariant::factory()->grain()->create();
    $warehouse ??= Warehouse::factory()->create();

    $totalOnHand = array_reduce($lotQuantities, fn (string $carry, string $quantity): string => bcadd($carry, $quantity, 6), '0.000000');
    $damaged = $lotQuantities[StockCondition::Damaged->value] ?? '0.000000';
    $saleable = $lotQuantities[StockCondition::Saleable->value] ?? '0.000000';

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => $totalOnHand,
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => $damaged,
        'available_quantity' => $saleable,
    ]);

    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => $totalOnHand,
        'reserved_quantity' => '0.000000',
    ]);

    foreach ([StockCondition::Saleable, StockCondition::Quarantine, StockCondition::Damaged] as $condition) {
        $quantity = $lotQuantities[$condition->value] ?? '0.000000';

        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition,
            'on_hand_base_quantity' => $quantity,
            'reserved_base_quantity' => '0.000000',
        ]);

        InventoryLotBalance::query()->firstOrNew([
            'inventory_lot_id' => $lot->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition->value,
        ])->forceFill([
            'on_hand_base_quantity' => $quantity,
            'reserved_base_quantity' => '0.000000',
        ])->save();
    }

    return [$variant, $warehouse, $lot];
}

it('generates one line per variant x lot x serial x condition in scope, and none outside it', function (): void {
    $warehouse = Warehouse::factory()->create();

    [$variant, , $lot] = lotTrackedCountFixture($warehouse, [
        StockCondition::Saleable->value => '10.000000',
        StockCondition::Damaged->value => '2.000000',
    ]);

    $twoLotVariant = ProductVariant::factory()->expiryMaterial()->create();
    $lotA = InventoryLot::factory()->for($twoLotVariant, 'productVariant')->for($warehouse)->create(['on_hand_quantity' => '5.000000']);
    $lotB = InventoryLot::factory()->for($twoLotVariant, 'productVariant')->for($warehouse)->create(['on_hand_quantity' => '3.000000']);

    $otherWarehouse = Warehouse::factory()->create();
    $serialVariant = ProductVariant::factory()->machine()->create();

    $unitInScope = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $serialVariant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'stock_condition' => StockCondition::Saleable,
        'custody_type' => SerializedCustodyType::Warehouse,
    ]);

    $unitOutOfScope = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $serialVariant->getKey(),
        'warehouse_id' => $otherWarehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
        'stock_condition' => StockCondition::Saleable,
        'custody_type' => SerializedCustodyType::Warehouse,
    ]);

    $count = app(InventoryCountService::class)->open(
        inventoryCountScope($warehouse, [StockCondition::Saleable->value]),
        User::factory()->create(),
    );

    // 1 (lot, saleable only - damaged out of scope) + 2 (two-lot variant) + 1 (serial unit in warehouse).
    expect($count->lines()->count())->toBe(4)
        ->and($count->lines()->where('product_variant_id', $variant->getKey())->count())->toBe(1)
        ->and($count->lines()->where('inventory_lot_id', $lot->getKey())->where('stock_condition', StockCondition::Damaged->value)->exists())->toBeFalse()
        ->and($count->lines()->where('product_variant_id', $twoLotVariant->getKey())->count())->toBe(2)
        ->and($count->lines()->where('inventory_lot_id', $lotA->getKey())->exists())->toBeTrue()
        ->and($count->lines()->where('inventory_lot_id', $lotB->getKey())->exists())->toBeTrue()
        ->and($count->lines()->where('serialized_inventory_unit_id', $unitInScope->getKey())->exists())->toBeTrue()
        ->and($count->lines()->where('serialized_inventory_unit_id', $unitOutOfScope->getKey())->exists())->toBeFalse()
        ->and($count->lines()->where('stock_condition', StockCondition::Damaged->value)->count())->toBe(0);
});

it('distinguishes an uncounted line from a zero count and refuses submission unless partial', function (): void {
    $warehouse = Warehouse::factory()->create();

    [$variantA] = lotTrackedCountFixture($warehouse, [StockCondition::Saleable->value => '10.000000']);
    lotTrackedCountFixture($warehouse, [StockCondition::Saleable->value => '4.000000']);

    $actor = User::factory()->create();
    $service = app(InventoryCountService::class);
    $count = $service->open(inventoryCountScope($warehouse, [StockCondition::Saleable->value]), $actor);

    expect($count->lines()->count())->toBe(2);

    $lineA = $count->lines()->where('product_variant_id', $variantA->getKey())->sole();

    expect($lineA->getRawOriginal('counted_base_quantity'))->toBeNull();

    $service->recordCount($lineA, '0', $actor);
    $lineA->refresh();

    expect($lineA->getRawOriginal('counted_base_quantity'))->not->toBeNull()
        ->and($lineA->counted_base_quantity)->toBe('0.000000');

    expect(fn () => $service->submitForReview($count, $actor))
        ->toThrow(DomainException::class, 'uncounted lines');

    $submitted = $service->submitForReview($count, $actor, partial: true);

    expect($submitted->status)->toBe(InventoryCountStatus::PendingReview)
        ->and($submitted->is_partial)->toBeTrue();
});

it('never lets variance be entered directly - it is always derived by the service', function (): void {
    $warehouse = Warehouse::factory()->create();
    lotTrackedCountFixture($warehouse, [StockCondition::Saleable->value => '10.000000']);

    $count = app(InventoryCountService::class)->open(
        inventoryCountScope($warehouse, [StockCondition::Saleable->value]),
        User::factory()->create(),
    );

    $line = $count->lines()->sole();

    $line->fill(['variance_base_quantity' => '999.000000'])->save();

    expect($line->fresh()->variance_base_quantity)->toBeNull();
});

it('auto-flags a materiality-breaching variance for recount and blocks confirmation until resolved', function (): void {
    $warehouse = Warehouse::factory()->create();
    [$variant] = lotTrackedCountFixture($warehouse, [StockCondition::Saleable->value => '10.000000']);

    SupplierProductReference::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '20.00',
        'is_active' => true,
    ]);

    $counter = User::factory()->create();
    $confirmer = User::factory()->create();
    $service = app(InventoryCountService::class);

    $count = $service->open(
        inventoryCountScope($warehouse, [StockCondition::Saleable->value], materialityThresholdMinor: 100),
        $counter,
    );

    $line = $count->lines()->sole();
    $service->recordCount($line, '5', $counter);

    $submitted = $service->submitForReview($count, $counter);

    expect($line->fresh()->recount_requested)->toBeTrue();

    expect(fn () => $service->confirm($submitted, $confirmer))
        ->toThrow(DomainException::class, 'flagged for recount');

    $service->acceptVariance($line->fresh(), $confirmer, 'Verified shortage with supervisor.');

    $confirmed = $service->confirm($submitted->fresh(), $confirmer);

    expect($confirmed->status)->toBe(InventoryCountStatus::Confirmed)
        ->and(InventoryAdjustment::query()->count())->toBe(1);
});

it('rejects the counter confirming their own count, reusing the shared maker-checker concern', function (): void {
    expect(in_array(EnforcesMakerChecker::class, class_uses(InventoryCountService::class), true))->toBeTrue();

    $warehouse = Warehouse::factory()->create();
    lotTrackedCountFixture($warehouse, [StockCondition::Saleable->value => '10.000000']);

    $actor = User::factory()->create();
    $service = app(InventoryCountService::class);
    $count = $service->open(inventoryCountScope($warehouse, [StockCondition::Saleable->value]), $actor);
    $line = $count->lines()->sole();
    $service->recordCount($line, '10', $actor);

    $submitted = $service->submitForReview($count, $actor);

    expect(fn () => $service->confirm($submitted, $actor))
        ->toThrow(SelfConfirmationRejected::class);

    expect(InventoryAdjustment::query()->count())->toBe(0)
        ->and($submitted->fresh()->status)->toBe(InventoryCountStatus::PendingReview);
});

it('confirms into exactly one adjustment carrying the correct condition per line, matching balances at every grain', function (): void {
    $warehouse = Warehouse::factory()->create();
    [$variant] = lotTrackedCountFixture($warehouse, [
        StockCondition::Saleable->value => '10.000000',
        StockCondition::Damaged->value => '2.000000',
    ]);

    $counter = User::factory()->create();
    $confirmer = User::factory()->create();
    $service = app(InventoryCountService::class);

    $count = $service->open(
        inventoryCountScope($warehouse, [StockCondition::Saleable->value, StockCondition::Damaged->value]),
        $counter,
    );

    expect($count->lines()->count())->toBe(2);

    $saleableLine = $count->lines()->where('stock_condition', StockCondition::Saleable->value)->sole();
    $damagedLine = $count->lines()->where('stock_condition', StockCondition::Damaged->value)->sole();

    $service->recordCount($saleableLine, '8', $counter);
    $service->recordCount($damagedLine, '1', $counter);

    $submitted = $service->submitForReview($count, $counter);
    $confirmed = $service->confirm($submitted, $confirmer);

    expect($confirmed->status)->toBe(InventoryCountStatus::Confirmed)
        ->and($confirmed->inventory_adjustment_id)->not->toBeNull()
        ->and(InventoryAdjustment::query()->count())->toBe(1);

    $adjustment = InventoryAdjustment::query()->findOrFail($confirmed->inventory_adjustment_id);

    expect($adjustment->items()->count())->toBe(2);

    $items = $adjustment->items()->get()->keyBy(fn (InventoryAdjustmentItem $item): string => $item->stock_condition->value);

    expect($items->get(StockCondition::Saleable->value)?->new_quantity)->toBe('8.000000')
        ->and($items->get(StockCondition::Damaged->value)?->new_quantity)->toBe('1.000000')
        ->and(InventoryConditionBalance::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_condition', StockCondition::Saleable->value)
            ->value('on_hand_base_quantity'))->toBe('8.000000')
        ->and(InventoryConditionBalance::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_condition', StockCondition::Damaged->value)
            ->value('on_hand_base_quantity'))->toBe('1.000000');
});

it('posts nothing when a count is cancelled', function (): void {
    $warehouse = Warehouse::factory()->create();
    lotTrackedCountFixture($warehouse, [StockCondition::Saleable->value => '10.000000']);

    $actor = User::factory()->create();
    $service = app(InventoryCountService::class);
    $count = $service->open(inventoryCountScope($warehouse, [StockCondition::Saleable->value]), $actor);

    $cancelled = $service->cancel($count, $actor, 'Warehouse access blocked mid-count.');

    expect($cancelled->status)->toBe(InventoryCountStatus::Cancelled)
        ->and(InventoryAdjustment::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});
