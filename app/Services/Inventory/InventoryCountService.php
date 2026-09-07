<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\CountScopeData;
use App\Enums\AdjustmentStatus;
use App\Enums\ConditionChangeReason;
use App\Enums\CountScope;
use App\Enums\InventoryCountStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\StockCondition;
use App\Exceptions\Domain\IllegalStatusTransition;
use App\Exceptions\Domain\SelfConfirmationRejected;
use App\Models\InventoryAdjustment;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryLot;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Concerns\EnforcesMakerChecker;
use App\Services\Sales\DocumentNumberGenerator;
use App\Services\Support\ServiceRecordPartService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The physical count worksheet document (GAP-MW-06, IN-06).
 *
 * A count never posts stock itself: {@see self::confirm()} produces at most
 * one {@see InventoryAdjustment}, confirmed through the existing
 * {@see InventoryAdjustmentService} — a second stock-correction path here
 * would be a second reconciliation truth. `counted_base_quantity` on
 * {@see InventoryCountLine} is nullable and every method here preserves that
 * distinction: null means "not yet counted", never "counted as zero".
 */
final readonly class InventoryCountService
{
    use EnforcesMakerChecker;

    private const int QUANTITY_SCALE = 6;

    public function __construct(
        private DocumentNumberGenerator $numbers,
        private InventoryAdjustmentService $adjustmentService,
    ) {}

    /**
     * Opens a count and snapshots the current system position for every
     * grain (variant x lot x serial x condition) the scope covers, so an
     * uncounted grain stays visible instead of being silently assumed
     * correct.
     */
    public function open(CountScopeData $data, User $actor): InventoryCount
    {
        return DB::transaction(function () use ($data, $actor): InventoryCount {
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($data->warehouseId);

            if (! $warehouse->is_active) {
                throw new DomainException('Cannot open a physical count for an inactive warehouse.');
            }

            if ($data->scopeType->requiresProductCategory() && $data->productCategoryId === null) {
                throw new DomainException('A category-scoped count requires a product category.');
            }

            if ($data->scopeType->requiresInventoryLot() && $data->inventoryLotId === null) {
                throw new DomainException('A lot-scoped count requires a lot.');
            }

            if ($data->scopeType->requiresVariantSet() && ($data->productVariantIds === null || $data->productVariantIds === [])) {
                throw new DomainException('A variant-set count requires at least one product variant.');
            }

            $conditions = $this->resolveConditions($data->conditions);

            $countNumber = $this->numbers->next(InventoryCount::withTrashed(), 'count_number', 'CNT-');

            $count = InventoryCount::query()->forceCreate([
                'count_number' => $countNumber,
                'status' => InventoryCountStatus::Draft,
                'scope_type' => $data->scopeType,
                'warehouse_id' => $warehouse->getKey(),
                'product_category_id' => $data->productCategoryId,
                'inventory_lot_id' => $data->inventoryLotId,
                'conditions' => array_map(static fn (StockCondition $condition): string => $condition->value, $conditions),
                'materiality_threshold_minor' => $data->materialityThresholdMinor,
                'opened_at' => now(),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            foreach ($this->scopedVariantIds($data) as $variantId) {
                $this->generateLinesForVariant($count, $variantId, $data, $conditions);
            }

            if ($count->lines()->count() === 0) {
                throw new DomainException('The selected scope produced no lines to count.');
            }

            $count->forceFill(['status' => InventoryCountStatus::Counting])->save();

            activity()
                ->performedOn($count)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'ip_address' => request()->ip(),
                    'line_count' => $count->lines()->count(),
                ])
                ->log('inventory.count.opened');

            return $count->refresh();
        }, attempts: 5);
    }

    /**
     * Records a counted quantity for one line. The variance is always
     * derived here from `counted - system`; callers never set it directly.
     * A zero count is legitimate — only a negative or non-decimal string is
     * rejected.
     */
    public function recordCount(InventoryCountLine $line, string $quantity, User $actor): InventoryCountLine
    {
        return DB::transaction(function () use ($line, $quantity, $actor): InventoryCountLine {
            $locked = InventoryCountLine::query()->lockForUpdate()->findOrFail($this->integerKey($line, 'inventory count line'));
            $count = InventoryCount::query()->lockForUpdate()->findOrFail($locked->inventory_count_id);

            if (! in_array($count->status, [InventoryCountStatus::Draft, InventoryCountStatus::Counting], true)) {
                throw new DomainException('Counts can only be recorded while the count is open for counting.');
            }

            $normalized = $this->nonNegativeQuantity($quantity);
            $previousCounted = $locked->counted_base_quantity;
            $wasFlagged = (bool) $locked->recount_requested;

            $variance = bcsub($normalized, (string) $locked->system_base_quantity, self::QUANTITY_SCALE);
            $varianceValueMinor = $this->varianceValueMinor((int) $locked->product_variant_id, $variance);

            $clearsFlag = $wasFlagged
                && $previousCounted !== null
                && bccomp((string) $previousCounted, $normalized, self::QUANTITY_SCALE) !== 0;

            $locked->forceFill([
                'counted_base_quantity' => $normalized,
                'variance_base_quantity' => $variance,
                'variance_value_minor' => $varianceValueMinor,
                'recount_requested' => $clearsFlag ? false : $wasFlagged,
            ])->save();

            if ($count->counted_by === null) {
                $count->forceFill(['counted_by' => $actor->getKey()])->save();
            }

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('inventory.count.line_recorded');

            return $locked->refresh();
        }, attempts: 5);
    }

    /**
     * Manual "please recount this line" action, and also the mechanism
     * IN-06's materiality escalation flags a line through in
     * {@see self::submitForReview()}. A flagged line blocks
     * {@see self::confirm()} until it is either re-counted to a different
     * value (which clears the flag automatically) or explicitly accepted via
     * {@see self::acceptVariance()}.
     */
    public function requestRecount(InventoryCountLine $line, User $actor, string $reason): InventoryCountLine
    {
        $reason = mb_trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to request a recount.');
        }

        return DB::transaction(function () use ($line, $actor, $reason): InventoryCountLine {
            $locked = InventoryCountLine::query()->lockForUpdate()->findOrFail($this->integerKey($line, 'inventory count line'));
            $count = InventoryCount::query()->lockForUpdate()->findOrFail($locked->inventory_count_id);

            if (! in_array($count->status, [InventoryCountStatus::Counting, InventoryCountStatus::PendingReview], true)) {
                throw new DomainException('Recounts can only be requested while the count is open.');
            }

            $locked->forceFill(['recount_requested' => true, 'note' => $reason])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip(), 'reason' => $reason])
                ->log('inventory.count.recount_requested');

            return $locked->refresh();
        }, attempts: 5);
    }

    /**
     * Explicitly accepts a materiality-flagged variance without a further
     * recount, recording the required reason in the same `note` column
     * {@see self::requestRecount()} uses (one column, one meaning: "why this
     * line's flag state is what it is").
     */
    public function acceptVariance(InventoryCountLine $line, User $actor, string $reason): InventoryCountLine
    {
        $reason = mb_trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to accept a flagged variance.');
        }

        return DB::transaction(function () use ($line, $actor, $reason): InventoryCountLine {
            $locked = InventoryCountLine::query()->lockForUpdate()->findOrFail($this->integerKey($line, 'inventory count line'));

            if (! $locked->recount_requested) {
                throw new DomainException('This line is not flagged for recount.');
            }

            $locked->forceFill(['recount_requested' => false, 'note' => $reason])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip(), 'reason' => $reason])
                ->log('inventory.count.variance_accepted');

            return $locked->refresh();
        }, attempts: 5);
    }

    /**
     * Refuses while any line is uncounted unless the operator explicitly
     * records that decision by passing `$partial`, in which case the
     * uncounted grains are simply left out of any resulting adjustment
     * rather than assumed correct. Also runs IN-06's materiality
     * escalation: any line whose variance value exceeds the count's
     * materiality threshold is auto-flagged for recount here (skipped
     * entirely when the count has no threshold).
     */
    public function submitForReview(InventoryCount $count, User $actor, bool $partial = false): InventoryCount
    {
        return DB::transaction(function () use ($count, $actor, $partial): InventoryCount {
            $locked = InventoryCount::query()->lockForUpdate()->findOrFail($this->integerKey($count, 'inventory count'));
            $this->assertTransition($locked, InventoryCountStatus::PendingReview);

            $lines = $locked->lines()->lockForUpdate()->get();
            $uncounted = $lines->filter(static fn (InventoryCountLine $line): bool => $line->counted_base_quantity === null);

            if ($uncounted->isNotEmpty() && ! $partial) {
                throw new DomainException('This count has uncounted lines. Finish counting or submit explicitly as partial.');
            }

            if ($locked->materiality_threshold_minor !== null) {
                foreach ($lines as $line) {
                    if (
                        ! $line->recount_requested
                        && $line->variance_value_minor !== null
                        && abs($line->variance_value_minor) > $locked->materiality_threshold_minor
                    ) {
                        $line->forceFill(['recount_requested' => true])->save();
                    }
                }
            }

            $locked->forceFill([
                'status' => InventoryCountStatus::PendingReview,
                'is_partial' => $partial && $uncounted->isNotEmpty(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'ip_address' => request()->ip(),
                    'partial' => $partial,
                    'uncounted_lines' => $uncounted->count(),
                ])
                ->log('inventory.count.submitted_for_review');

            return $locked->refresh();
        }, attempts: 5);
    }

    /**
     * Maker distinct from checker (CC-03): the actor recorded as `counted_by` may not
     * also confirm. Creates exactly one {@see InventoryAdjustment} carrying
     * one item per non-zero-variance, counted line (each with its own
     * `stock_condition`), then confirms it through the existing
     * {@see InventoryAdjustmentService} — this method never posts stock
     * itself. When every counted line matches the system, no adjustment is
     * created at all.
     */
    public function confirm(InventoryCount $count, User $actor): InventoryCount
    {
        return DB::transaction(function () use ($count, $actor): InventoryCount {
            $locked = InventoryCount::query()->lockForUpdate()->findOrFail($this->integerKey($count, 'inventory count'));
            $this->assertTransition($locked, InventoryCountStatus::Confirmed);

            $counterId = is_numeric($locked->counted_by) ? (int) $locked->counted_by : null;

            if ($this->sameActor($counterId, $actor)) {
                throw SelfConfirmationRejected::forInventoryCount($locked);
            }

            $lines = $locked->lines()->lockForUpdate()->get();

            if ($lines->contains(static fn (InventoryCountLine $line): bool => $line->recount_requested)) {
                throw new DomainException('This count has lines flagged for recount that must be resolved before confirmation.');
            }

            $varianceLines = $lines->filter(static function (InventoryCountLine $line): bool {
                if ($line->counted_base_quantity === null || $line->variance_base_quantity === null) {
                    return false;
                }

                return bccomp((string) $line->variance_base_quantity, '0', self::QUANTITY_SCALE) !== 0;
            });

            $adjustment = $varianceLines->isNotEmpty()
                ? $this->createVarianceAdjustment($locked, $varianceLines, $actor)
                : null;

            $locked->forceFill([
                'status' => InventoryCountStatus::Confirmed,
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
                'closed_at' => now(),
                'inventory_adjustment_id' => $adjustment?->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'ip_address' => request()->ip(),
                    'inventory_adjustment_id' => $adjustment?->getKey(),
                    'variance_line_count' => $varianceLines->count(),
                ])
                ->log('inventory.count.confirmed');

            return $locked->refresh();
        }, attempts: 5);
    }

    public function cancel(InventoryCount $count, User $actor, string $reason): InventoryCount
    {
        $reason = mb_trim($reason);

        if ($reason === '') {
            throw new DomainException('A cancellation reason is required.');
        }

        return DB::transaction(function () use ($count, $actor, $reason): InventoryCount {
            $locked = InventoryCount::query()->lockForUpdate()->findOrFail($this->integerKey($count, 'inventory count'));
            $this->assertTransition($locked, InventoryCountStatus::Cancelled);

            $locked->forceFill([
                'status' => InventoryCountStatus::Cancelled,
                'closed_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip(), 'reason' => $reason])
                ->log('inventory.count.cancelled');

            return $locked->refresh();
        }, attempts: 5);
    }

    /**
     * The reviewable worksheet: per-grain system/counted/variance/value,
     * materiality-flagged exceptions first, with an explicit `unvalued`
     * marker where no cost is on record instead of silently valuing at zero.
     *
     * @return list<array{
     *   line_id:int,
     *   product_variant_id:int,
     *   stock_condition:string,
     *   system_base_quantity:string,
     *   counted_base_quantity:string|null,
     *   variance_base_quantity:string|null,
     *   variance_value_minor:int|null,
     *   unvalued:bool,
     *   recount_requested:bool
     * }>
     */
    public function variance(InventoryCount $count): array
    {
        $mapped = $count->lines()
            ->orderByDesc('recount_requested')
            ->orderBy('id')
            ->get()
            ->map(function (InventoryCountLine $line): array {
                $hasVariance = $line->variance_base_quantity !== null
                    && bccomp((string) $line->variance_base_quantity, '0', self::QUANTITY_SCALE) !== 0;

                return [
                    'line_id' => $this->integerKey($line, 'inventory count line'),
                    'product_variant_id' => (int) $line->product_variant_id,
                    'stock_condition' => $line->stock_condition->value,
                    'system_base_quantity' => (string) $line->system_base_quantity,
                    'counted_base_quantity' => $line->counted_base_quantity !== null ? (string) $line->counted_base_quantity : null,
                    'variance_base_quantity' => $line->variance_base_quantity !== null ? (string) $line->variance_base_quantity : null,
                    'variance_value_minor' => $line->variance_value_minor,
                    'unvalued' => $hasVariance && $line->variance_value_minor === null,
                    'recount_requested' => (bool) $line->recount_requested,
                ];
            })
            ->all();

        return array_values($mapped);
    }

    /**
     * @param  Collection<int, InventoryCountLine>  $varianceLines
     */
    private function createVarianceAdjustment(
        InventoryCount $count,
        Collection $varianceLines,
        User $actor,
    ): InventoryAdjustment {
        $adjustment = InventoryAdjustment::query()->forceCreate([
            'warehouse_id' => $count->warehouse_id,
            'reason' => sprintf('Variance from physical count %s.', (string) $count->count_number),
            'reason_category' => ConditionChangeReason::Other,
            'status' => AdjustmentStatus::Draft,
            'created_by' => $count->counted_by ?? $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);

        foreach ($varianceLines as $line) {
            $adjustment->items()->create([
                'product_variant_id' => $line->product_variant_id,
                'stock_condition' => $line->stock_condition,
                'inventory_lot_id' => $line->inventory_lot_id,
                'serialized_inventory_unit_id' => $line->serialized_inventory_unit_id,
                'new_quantity' => $line->counted_base_quantity,
            ]);
        }

        $this->adjustmentService->confirm($adjustment, $actor);

        return $adjustment->refresh();
    }

    /**
     * @param  array<mixed>  $values
     * @return list<StockCondition>
     */
    private function resolveConditions(array $values): array
    {
        if ($values === []) {
            return [StockCondition::Saleable, StockCondition::Quarantine, StockCondition::Damaged];
        }

        return array_values(array_map(static function (mixed $value): StockCondition {
            if (! is_string($value)) {
                throw new DomainException('Stock conditions in scope must be strings.');
            }

            $condition = StockCondition::tryFrom($value);

            if (! $condition instanceof StockCondition || ! $condition->isMaterialized()) {
                throw new DomainException(sprintf('"%s" is not a valid materialized stock condition.', $value));
            }

            return $condition;
        }, $values));
    }

    /** @return list<int> */
    private function scopedVariantIds(CountScopeData $data): array
    {
        return match ($data->scopeType) {
            CountScope::VariantSet => array_values(array_unique($data->productVariantIds ?? [])),
            CountScope::Category => $this->idList(ProductVariant::query()
                ->whereHas('product', fn ($query) => $query->where('category_id', $data->productCategoryId))
                ->pluck('id')),
            CountScope::Lot => [$this->lotVariantId($data)],
            CountScope::Warehouse => $this->variantIdsWithPresenceInWarehouse($data->warehouseId),
        };
    }

    private function lotVariantId(CountScopeData $data): int
    {
        $lot = InventoryLot::query()->findOrFail($data->inventoryLotId);

        return (int) $lot->product_variant_id;
    }

    /** @return list<int> */
    private function variantIdsWithPresenceInWarehouse(int $warehouseId): array
    {
        $fromAggregateBalances = InventoryConditionBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->pluck('product_variant_id');

        $fromLotBalances = DB::table('inventory_lot_balances')
            ->join('inventory_lots', 'inventory_lots.id', '=', 'inventory_lot_balances.inventory_lot_id')
            ->whereNull('inventory_lots.canonical_inventory_lot_id')
            ->where('inventory_lot_balances.warehouse_id', $warehouseId)
            ->pluck('inventory_lots.product_variant_id');

        $fromSerializedUnits = SerializedInventoryUnit::query()
            ->where('warehouse_id', $warehouseId)
            ->where('custody_type', SerializedCustodyType::Warehouse->value)
            ->pluck('product_variant_id');

        return $this->idList($fromAggregateBalances->merge($fromLotBalances)->merge($fromSerializedUnits)->unique());
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return list<int>
     */
    private function idList(Collection $ids): array
    {
        $result = [];

        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $result[] = (int) $id;
            }
        }

        return array_values(array_unique($result));
    }

    /** @param list<StockCondition> $conditions */
    private function generateLinesForVariant(
        InventoryCount $count,
        int $variantId,
        CountScopeData $data,
        array $conditions,
    ): void {
        $variant = ProductVariant::query()->with('product')->findOrFail($variantId);
        $conditionValues = array_map(static fn (StockCondition $condition): string => $condition->value, $conditions);
        $tracksSerials = $variant->productType()?->tracksSerials() === true;
        $tracksBatches = $variant->productType()?->tracksBatches() === true;

        if ($tracksSerials) {
            $query = SerializedInventoryUnit::query()
                ->where('product_variant_id', $variantId)
                ->where('warehouse_id', $count->warehouse_id)
                ->where('custody_type', SerializedCustodyType::Warehouse->value)
                ->whereIn('stock_condition', $conditionValues);

            if ($data->scopeType->requiresInventoryLot()) {
                $query->where('inventory_lot_id', $data->inventoryLotId);
            }

            foreach ($query->get() as $unit) {
                $this->createLine(
                    $count,
                    $variantId,
                    $unit->inventory_lot_id,
                    (int) $unit->id,
                    $unit->stock_condition,
                    '1.000000',
                );
            }

            return;
        }

        if ($tracksBatches) {
            $query = DB::table('inventory_lot_balances')
                ->join('inventory_lots', 'inventory_lots.id', '=', 'inventory_lot_balances.inventory_lot_id')
                ->where('inventory_lots.product_variant_id', $variantId)
                ->whereNull('inventory_lots.canonical_inventory_lot_id')
                ->where('inventory_lot_balances.warehouse_id', $count->warehouse_id)
                ->whereIn('inventory_lot_balances.stock_condition', $conditionValues)
                ->select([
                    'inventory_lot_balances.inventory_lot_id',
                    'inventory_lot_balances.stock_condition',
                    'inventory_lot_balances.on_hand_base_quantity',
                ]);

            if ($data->scopeType->requiresInventoryLot()) {
                $query->where('inventory_lot_balances.inventory_lot_id', $data->inventoryLotId);
            }

            foreach ($query->get() as $row) {
                $stockConditionValue = $row->stock_condition;
                $lotId = $row->inventory_lot_id;
                $onHand = $row->on_hand_base_quantity;

                if (! is_string($stockConditionValue) || ! is_numeric($lotId) || ! is_numeric($onHand)) {
                    throw new LogicException('Inventory lot balances must carry a valid lot, condition, and quantity.');
                }

                $condition = StockCondition::tryFrom($stockConditionValue);

                if (! $condition instanceof StockCondition) {
                    throw new LogicException('Inventory lot balances must carry a valid stock condition.');
                }

                $this->createLine(
                    $count,
                    $variantId,
                    (int) $lotId,
                    null,
                    $condition,
                    bcadd((string) $onHand, '0', self::QUANTITY_SCALE),
                );
            }

            return;
        }

        $balances = InventoryConditionBalance::query()
            ->where('product_variant_id', $variantId)
            ->where('warehouse_id', $count->warehouse_id)
            ->whereIn('stock_condition', $conditionValues)
            ->get();

        foreach ($balances as $balance) {
            $this->createLine(
                $count,
                $variantId,
                null,
                null,
                $balance->stock_condition,
                bcadd((string) $balance->on_hand_base_quantity, '0', self::QUANTITY_SCALE),
            );
        }
    }

    private function createLine(
        InventoryCount $count,
        int $variantId,
        ?int $lotId,
        ?int $serializedUnitId,
        StockCondition $condition,
        string $systemQuantity,
    ): void {
        InventoryCountLine::query()->forceCreate([
            'inventory_count_id' => $count->getKey(),
            'product_variant_id' => $variantId,
            'inventory_lot_id' => $lotId,
            'serialized_inventory_unit_id' => $serializedUnitId,
            'stock_condition' => $condition,
            'system_base_quantity' => $systemQuantity,
            'counted_base_quantity' => null,
        ]);
    }

    /** @return numeric-string */
    private function nonNegativeQuantity(string $quantity): string
    {
        if (! is_numeric($quantity) || preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/D', $quantity) !== 1) {
            throw new DomainException('Counted quantities must be an exact non-negative base-UOM decimal with at most six places.');
        }

        return bcadd($quantity, '0', self::QUANTITY_SCALE);
    }

    /**
     * Snapshots the variant's last-received unit cost, the same
     * "most-recently-updated active supplier reference" lookup
     * {@see ServiceRecordPartService::resolveCostSnapshot()}
     * uses (WP-2.9). Returns null (unvalued) rather than defaulting to zero
     * when no cost is on record.
     */
    /** @param numeric-string $variance */
    private function varianceValueMinor(int $productVariantId, string $variance): ?int
    {
        if (bccomp($variance, '0', self::QUANTITY_SCALE) === 0) {
            return 0;
        }

        $reference = SupplierProductReference::query()
            ->where('product_variant_id', $productVariantId)
            ->where('is_active', true)
            ->whereNotNull('purchase_cost')
            ->orderByDesc('updated_at')
            ->first();

        if (! $reference instanceof SupplierProductReference || $reference->purchase_cost === null) {
            return null;
        }

        $costMinor = (int) round(((float) $reference->purchase_cost) * 100);

        return (int) round(((float) $variance) * $costMinor);
    }

    private function assertTransition(InventoryCount $count, InventoryCountStatus $target): void
    {
        if ($count->status->canTransitionTo($target)) {
            return;
        }

        throw IllegalStatusTransition::between(
            'Inventory count '.$count->count_number,
            $count->status->value,
            $target->value,
        );
    }

    private function integerKey(object $model, string $label): int
    {
        if (! method_exists($model, 'getKey')) {
            throw new LogicException($label.' must be an Eloquent model.');
        }

        $key = $model->getKey();

        if (! is_int($key)) {
            throw new LogicException($label.' identifiers must be integers.');
        }

        return $key;
    }
}
