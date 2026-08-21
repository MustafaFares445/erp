<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Events\InventoryOperationCompleted;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\ProductVariant;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Drives the {@see InventoryOperation} lifecycle behind every Receipt, Delivery and Internal
 * Transfer (US1, contracts/inventory-operations.md).
 *
 * The single invariant every method upholds (R-001, FR-003): a warehouse's on-hand balance
 * changes exactly when that warehouse's custody of the goods changes — never at Draft, Waiting,
 * or Ready. `markReady()` only reserves; `dispatch()`/`complete()` are the sole methods that ever
 * move `on_hand_quantity`.
 *
 * Every method locks the operation row (P-3) inside its own transaction and re-checks the stage
 * after acquiring the lock, so the loser of a concurrent confirmation sees the *current*
 * persisted stage rather than the one it read before the race (G-5).
 */
final readonly class InventoryOperationService
{
    public function __construct(
        private InventoryBalanceService $inventoryBalanceService,
        private InventoryLotService $inventoryLotService,
        private ProductTypeGuard $productTypeGuard,
    ) {}

    /**
     * Reserves outbound quantity and moves Draft/Waiting → Ready, or holds at Waiting when a
     * source warehouse cannot cover the required quantity (FR-004, FR-005).
     *
     * @throws DomainException
     */
    public function markReady(InventoryOperation $operation, ?User $actor = null): InventoryOperation
    {
        return DB::transaction(function () use ($operation, $actor): InventoryOperation {
            $locked = $this->lock($operation);
            $this->guardStageIsOneOf($locked, [OperationStage::Draft, OperationStage::Waiting]);

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();

            if ($lines->isEmpty()) {
                throw new DomainException(__('admin.inventory.operation.errors.no_lines'));
            }

            $variants = $this->lockVariants($lines);
            $this->assertTypeRulesHold($lines, $variants, $locked);
            $this->assertNoDuplicateSerials($locked, $lines);

            $sourceWarehouseId = $locked->source_warehouse_id;
            $packageWarehouseId = $locked->operation_type === OperationType::Receipt
                ? $locked->destination_warehouse_id
                : $sourceWarehouseId;

            if (is_int($packageWarehouseId)) {
                $this->assertPackagesBelongToWarehouse($lines, $packageWarehouseId);
            }

            if ($locked->operation_type === OperationType::Receipt || ! is_int($sourceWarehouseId)) {
                return $this->transitionTo($locked, OperationStage::Ready);
            }

            $shortfall = $this->firstInsufficientVariant($lines, $sourceWarehouseId);

            if ($shortfall !== null) {
                return $this->transitionTo($locked, OperationStage::Waiting);
            }

            $this->reserveLines($lines, $sourceWarehouseId);
            // Reserving the lot too, not just the aggregate balance, is what stops a second
            // operation committing the same batch of an expiry-tracked material.
            $this->reserveLots($lines, $variants, $sourceWarehouseId, $actor);

            return $this->transitionTo($locked, OperationStage::Ready);
        }, attempts: 5);
    }

    /**
     * Releases the source's custody of an internal transfer — the sole use of the `InTransit`
     * stage (V-03, R-001).
     *
     * @throws DomainException
     */
    public function dispatch(InventoryOperation $operation, User $actor): InventoryOperation
    {
        return DB::transaction(function () use ($operation, $actor): InventoryOperation {
            $locked = $this->lock($operation);

            if ($locked->operation_type !== OperationType::InternalTransfer) {
                throw new DomainException(__('admin.inventory.operation.errors.illegal_transition', ['from' => $locked->stage->value, 'to' => OperationStage::InTransit->value]));
            }

            $this->guardStageIsOneOf($locked, [OperationStage::Ready]);

            $sourceWarehouseId = $locked->source_warehouse_id;

            if (! is_int($sourceWarehouseId)) {
                throw new DomainException(__('admin.inventory.operation.errors.illegal_transition', ['from' => $locked->stage->value, 'to' => OperationStage::InTransit->value]));
            }

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();
            $this->fulfillReservationAndLeave($lines, $sourceWarehouseId, $locked, $actor);

            $locked->forceFill(['dispatched_at' => now()]);

            return $this->transitionTo($locked, OperationStage::InTransit);
        }, attempts: 5);
    }

    /**
     * The custody-taking transition: destination gains for a receipt or a transfer already
     * `InTransit`; source loses for a delivery (R-001).
     *
     * @throws DomainException
     */
    public function complete(InventoryOperation $operation, ?User $actor = null): InventoryOperation
    {
        return DB::transaction(function () use ($operation, $actor): InventoryOperation {
            $locked = $this->lock($operation);
            $requiredStage = $locked->operation_type === OperationType::InternalTransfer
                ? OperationStage::InTransit
                : OperationStage::Ready;
            $this->guardStageIsOneOf($locked, [$requiredStage]);

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();

            match ($locked->operation_type) {
                OperationType::Receipt => $this->receiveLines($lines, $this->requireWarehouse($locked->destination_warehouse_id), $locked, $actor),
                OperationType::Delivery => $this->fulfillReservationAndLeave($lines, $this->requireWarehouse($locked->source_warehouse_id), $locked, $actor),
                OperationType::InternalTransfer => $this->receiveLines($lines, $this->requireWarehouse($locked->destination_warehouse_id), $locked, $actor),
            };

            if ($locked->operation_type === OperationType::InternalTransfer) {
                $this->movePackagesWithReceivedLines($lines, $this->requireWarehouse($locked->destination_warehouse_id));
            }

            $locked->forceFill(['completed_at' => now()]);

            $completed = $this->transitionTo($locked, OperationStage::Done);

            // Fired inside the transaction so anything reacting to a completed
            // operation commits with it or not at all. This carries no knowledge
            // of its listeners: Purchasing advances a purchase order's received
            // quantities from here, and Inventory stays unaware that Purchasing
            // exists (spec 017 research.md R-002).
            InventoryOperationCompleted::dispatch($completed, $actor);

            return $completed;
        }, attempts: 5);
    }

    /**
     * Terminates an operation before it is Done. Releases any reservation; from `InTransit`,
     * additionally restores the source's on-hand with a compensating movement, so FR-009's
     * guarantee — cancelling never leaves an on-hand balance changed — holds even though the
     * source already lost custody at dispatch (spec Edge Cases).
     *
     * @throws DomainException
     */
    public function cancel(InventoryOperation $operation, User $actor, string $reason): InventoryOperation
    {
        return DB::transaction(function () use ($operation, $actor, $reason): InventoryOperation {
            $locked = $this->lock($operation);

            if ($locked->stage === OperationStage::Done) {
                throw new DomainException(__('admin.inventory.operation.errors.immutable'));
            }

            $this->guardStageIsOneOf($locked, [
                OperationStage::Draft, OperationStage::Waiting, OperationStage::Ready, OperationStage::InTransit,
            ]);

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();

            if ($locked->stage === OperationStage::InTransit) {
                $sourceWarehouseId = $this->requireWarehouse($locked->source_warehouse_id);
                $variants = $this->lockVariants($lines);

                foreach ($lines as $line) {
                    $stock = $this->inventoryBalanceService->receive($line->product_variant_id, $sourceWarehouseId, (float) $line->quantity);
                    $variant = $variants[$line->product_variant_id] ?? null;

                    // The lot gave up its quantity at dispatch, so it has to get it back here,
                    // or the lot breakdown would permanently understate the restored on-hand.
                    if ($variant instanceof ProductVariant) {
                        $this->inventoryLotService->restore($line, $variant);
                    }

                    $this->recordMovement($line, $locked, $sourceWarehouseId, (float) $line->quantity, $actor);
                    $stock->refresh();
                }
            } elseif ($locked->stage === OperationStage::Ready) {
                $reservationWarehouseId = $this->reservationWarehouseId($locked);
                $this->releaseReservations($lines, $reservationWarehouseId);
                $this->releaseLots($lines, $this->lockVariants($lines));
            }

            $locked->forceFill(['canceled_at' => now(), 'notes' => mb_trim(($locked->notes ?? '').' '.$reason)]);

            $result = $this->transitionTo($locked, OperationStage::Canceled);

            activity()
                ->performedOn($result)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['stage' => $locked->getOriginal('stage')],
                    'attributes' => ['stage' => OperationStage::Canceled->value, 'reason' => $reason],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('inventory.operation.canceled');

            return $result;
        }, attempts: 5);
    }

    /**
     * Read-only: the resulting balance per line if the next custody-changing transition were
     * confirmed right now (FR-010, SRS §5.1). Mutates nothing.
     *
     * @return list<array{product_variant_id: int, warehouse_id: int, before: float, after: float}>
     */
    public function previewEffect(InventoryOperation $operation): array
    {
        $isUpcomingReceive = $operation->operation_type !== OperationType::InternalTransfer
            ? $operation->operation_type === OperationType::Receipt
            : $operation->stage === OperationStage::InTransit;

        $warehouseId = $isUpcomingReceive ? $operation->destination_warehouse_id : $operation->source_warehouse_id;

        if (! is_int($warehouseId)) {
            return [];
        }

        $preview = [];

        foreach ($operation->lines as $line) {
            $before = $this->decimal(InventoryStock::query()
                ->where('product_variant_id', $line->product_variant_id)
                ->where('warehouse_id', $warehouseId)
                ->value('on_hand_quantity'));

            $delta = $isUpcomingReceive ? (float) $line->quantity : -(float) $line->quantity;

            $preview[] = [
                'product_variant_id' => $line->product_variant_id,
                'warehouse_id' => $warehouseId,
                'before' => $before,
                'after' => $before + $delta,
            ];
        }

        return $preview;
    }

    /**
     * Read-only: current available quantity for one variant/warehouse pair, or null when no
     * stock row exists yet. The write surfaces above are the only ones allowed to touch
     * {@see InventoryStock} from outside this service (contracts/inventory-operations.md P-2);
     * this is that same boundary's read counterpart for callers that need a live balance check
     * before an operation exists to preview against, such as the create-operation form.
     */
    public function availableQuantity(int $productVariantId, int $warehouseId): ?float
    {
        $availableQuantity = InventoryStock::query()
            ->where('product_variant_id', $productVariantId)
            ->where('warehouse_id', $warehouseId)
            ->value('available_quantity');

        return is_numeric($availableQuantity) ? (float) $availableQuantity : null;
    }

    /**
     * Read-only: available quantity and variant name for each of the given variants in one
     * warehouse, keyed by product_variant_id. Batched counterpart of
     * {@see self::availableQuantity()}, for callers checking several lines at once.
     *
     * @param  list<int>  $productVariantIds
     * @return array<int, array{available_quantity: float, variant_name: ?string}>
     */
    public function availableQuantitiesFor(array $productVariantIds, int $warehouseId): array
    {
        return InventoryStock::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $productVariantIds)
            ->with('productVariant:id,name')
            ->get()
            ->mapWithKeys(fn (InventoryStock $stock): array => [
                $stock->product_variant_id => [
                    'available_quantity' => (float) $stock->available_quantity,
                    'variant_name' => $stock->productVariant?->name,
                ],
            ])
            ->all();
    }

    /** @throws DomainException */
    private function lock(InventoryOperation $operation): InventoryOperation
    {
        /** @var InventoryOperation $locked */
        $locked = InventoryOperation::query()->lockForUpdate()->findOrFail($operation->getKey());

        return $locked;
    }

    /**
     * @param  list<OperationStage>  $allowed
     *
     * @throws DomainException
     */
    private function guardStageIsOneOf(InventoryOperation $operation, array $allowed): void
    {
        if (in_array($operation->stage, $allowed, true)) {
            return;
        }

        if ($operation->isTerminal()) {
            throw new DomainException(__('admin.inventory.operation.errors.already_processed'));
        }

        throw new DomainException(__('admin.inventory.operation.errors.illegal_transition', [
            'from' => $operation->stage->value,
            'to' => $allowed[0]->value,
        ]));
    }

    private function transitionTo(InventoryOperation $operation, OperationStage $stage): InventoryOperation
    {
        $operationNumber = $operation->operation_number;

        if ($operationNumber === null && in_array($stage, [OperationStage::Ready, OperationStage::InTransit, OperationStage::Done], true)) {
            $operationNumber = $this->nextOperationNumber();
        }

        $operation->forceFill([
            'stage' => $stage,
            'operation_number' => $operationNumber,
        ]);
        $operation->save();

        return $operation->refresh();
    }

    /**
     * Locks every variant the operation touches, once, and asserts each is still sellable.
     *
     * Returns the variants keyed by id so the type-aware guards and the lot handling that follow
     * read the product type from an already-loaded relation instead of re-querying per line.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @return array<int, ProductVariant>
     *
     * @throws DomainException
     */
    private function lockVariants(Collection $lines): array
    {
        $variants = [];

        foreach ($lines as $line) {
            $productVariantId = $line->product_variant_id;

            if (isset($variants[$productVariantId])) {
                continue;
            }

            /** @var ProductVariant $variant */
            $variant = ProductVariant::query()->with(['product', 'unit'])->lockForUpdate()->findOrFail($productVariantId);

            if (! $variant->isOperational()) {
                throw new DomainException(__('admin.inventory.operation.errors.inactive_variant'));
            }

            $variants[$productVariantId] = $variant;
        }

        return $variants;
    }

    /**
     * Every product-type rule a line must satisfy before the operation may leave Draft.
     *
     * Quantity precision used to be checked here directly against the unit; it now goes through
     * {@see ProductTypeGuard} so the type's own rule — a machine is never fractional — is
     * applied by the same call, in the same place, as the unit's.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @param  array<int, ProductVariant>  $variants
     *
     * @throws DomainException
     */
    private function assertTypeRulesHold(Collection $lines, array $variants, InventoryOperation $operation): void
    {
        $isInbound = $operation->operation_type === OperationType::Receipt;

        foreach ($lines as $line) {
            $variant = $variants[$line->product_variant_id] ?? null;

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $this->productTypeGuard->assertQuantity($variant, (float) $line->quantity, $line->unit);
            $this->productTypeGuard->assertOperationLineSerial($variant, $line->serialized_inventory_unit_id, (float) $line->quantity);

            // A receipt line creates the lot, so it carries the expiry date; an outbound line
            // names an existing lot instead and is validated when that lot is drawn from.
            if ($isInbound) {
                $this->productTypeGuard->assertInboundExpiry($variant, $line->expires_at);
            }
        }
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     *
     * @throws DomainException
     */
    private function assertNoDuplicateSerials(InventoryOperation $operation, Collection $lines): void
    {
        foreach ($lines->whereNotNull('serialized_inventory_unit_id') as $line) {
            $conflict = InventoryOperationLine::query()
                ->where('serialized_inventory_unit_id', $line->serialized_inventory_unit_id)
                ->where('inventory_operation_id', '!=', $operation->getKey())
                ->whereHas('operation', fn (Builder $query): Builder => $query->where('stage', '!=', OperationStage::Canceled->value))
                ->with('serializedUnit')
                ->first();

            if ($conflict instanceof InventoryOperationLine) {
                $serial = $conflict->serializedUnit->serial_number ?? (string) $conflict->serialized_inventory_unit_id;

                throw new DomainException(__('admin.inventory.operation.errors.duplicate_serial', ['serial' => $serial]));
            }
        }
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function firstInsufficientVariant(Collection $lines, int $warehouseId): ?int
    {
        foreach ($lines->groupBy('product_variant_id') as $productVariantId => $variantLines) {
            if (! is_numeric($productVariantId)) {
                continue;
            }

            $stock = InventoryStock::query()
                ->where('product_variant_id', $productVariantId)
                ->where('warehouse_id', $warehouseId)
                ->first();

            $available = $stock instanceof InventoryStock ? (float) $stock->available_quantity : 0.0;

            if ($available < $this->decimal($variantLines->sum('quantity'))) {
                return (int) $productVariantId;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function reserveLines(Collection $lines, int $warehouseId): void
    {
        foreach ($lines->groupBy('product_variant_id') as $productVariantId => $variantLines) {
            if (! is_numeric($productVariantId)) {
                continue;
            }

            $stock = InventoryStock::query()
                ->where('product_variant_id', $productVariantId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($stock instanceof InventoryStock) {
                $this->inventoryBalanceService->reserve($stock, $this->decimal($variantLines->sum('quantity')));
            }
        }
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     *
     * @throws DomainException
     */
    private function assertPackagesBelongToWarehouse(Collection $lines, int $warehouseId): void
    {
        foreach ($lines as $line) {
            if (! Package::belongsToWarehouse($line->package_id, $warehouseId)) {
                throw new DomainException(__('admin.package.errors.warehouse_mismatch'));
            }
        }
    }

    /**
     * Consumes the reservation made at `markReady()` and lets custody leave the given warehouse —
     * the shared second half of `dispatch()` (transfer) and `complete()` (delivery). Releasing
     * the reservation first, then transferring out, means the availability check
     * `transferOut()` performs sees the warehouse's *true* remaining on-hand rather than a figure
     * still depressed by this operation's own now-fulfilled reservation.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function fulfillReservationAndLeave(Collection $lines, int $warehouseId, InventoryOperation $operation, ?User $actor): void
    {
        $this->releaseReservations($lines, $warehouseId);
        $variants = $this->lockVariants($lines);

        foreach ($lines as $line) {
            $variant = $variants[$line->product_variant_id] ?? null;

            // The lot is drawn from before the aggregate balance moves, so an expired or
            // short batch blocks the whole transition inside this transaction rather than
            // leaving the warehouse balance changed and the lot untouched.
            if ($variant instanceof ProductVariant) {
                $this->inventoryLotService->consume(
                    $line,
                    $variant,
                    $warehouseId,
                    $actor,
                    $this->mayReleaseExpiredStock($actor),
                );
            }

            $this->inventoryBalanceService->transferOut($line->product_variant_id, $warehouseId, (float) $line->quantity);
            $this->recordMovement($line, $operation, $warehouseId, -(float) $line->quantity, $actor);
        }
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function receiveLines(Collection $lines, int $warehouseId, InventoryOperation $operation, ?User $actor): void
    {
        $variants = $this->lockVariants($lines);

        foreach ($lines as $line) {
            $variant = $variants[$line->product_variant_id] ?? null;

            // Receiving an expiry-tracked material is what creates its lot. Before this, a
            // receipt confirmed through the operation document produced stock with no lot and
            // no expiry date at all, while the legacy receipt path rejected the same input.
            if ($variant instanceof ProductVariant) {
                $this->inventoryLotService->receive($line, $variant, $warehouseId);
            }

            $this->inventoryBalanceService->receive($line->product_variant_id, $warehouseId, (float) $line->quantity);
            $this->recordMovement($line->refresh(), $operation, $warehouseId, (float) $line->quantity, $actor);
        }
    }

    /**
     * Holds each expiry-tracked line's quantity against the lot it names, alongside the
     * aggregate reservation {@see self::reserveLines()} makes.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @param  array<int, ProductVariant>  $variants
     *
     * @throws DomainException
     */
    private function reserveLots(Collection $lines, array $variants, int $warehouseId, ?User $actor): void
    {
        foreach ($lines as $line) {
            $variant = $variants[$line->product_variant_id] ?? null;

            if ($variant instanceof ProductVariant) {
                $this->inventoryLotService->reserve($line, $variant, $warehouseId, $actor, $this->mayReleaseExpiredStock($actor));
            }
        }
    }

    /**
     * Returns each expiry-tracked line's reservation to its lot when an operation is cancelled
     * from Ready — the mirror of {@see self::reserveLots()}.
     *
     * @param  Collection<int, InventoryOperationLine>  $lines
     * @param  array<int, ProductVariant>  $variants
     */
    private function releaseLots(Collection $lines, array $variants): void
    {
        foreach ($lines as $line) {
            $variant = $variants[$line->product_variant_id] ?? null;

            if ($variant instanceof ProductVariant) {
                $this->inventoryLotService->release($line, $variant);
            }
        }
    }

    /**
     * Whether this actor may push expired goods through an outbound operation.
     *
     * A null actor — a scheduled or system-initiated transition with nobody to hold
     * accountable — never may, so the block stays in force by default rather than being
     * bypassed by the absence of a user.
     */
    private function mayReleaseExpiredStock(?User $actor): bool
    {
        return $actor?->can(InventoryPermission::ExpiredStockOverride->value) === true;
    }

    /** @param Collection<int, InventoryOperationLine> $lines */
    private function movePackagesWithReceivedLines(Collection $lines, int $warehouseId): void
    {
        foreach ($lines->whereNotNull('package_id')->unique('package_id') as $line) {
            $package = Package::query()->lockForUpdate()->find($line->package_id);

            if ($package instanceof Package) {
                $package->moveWithRecordedGoods($warehouseId);
            }
        }
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function releaseReservations(Collection $lines, int $warehouseId): void
    {
        foreach ($lines->groupBy('product_variant_id') as $productVariantId => $variantLines) {
            if (! is_numeric($productVariantId)) {
                continue;
            }

            $stock = InventoryStock::query()
                ->where('product_variant_id', $productVariantId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($stock instanceof InventoryStock && (float) $stock->reserved_quantity > 0) {
                $toRelease = min((float) $stock->reserved_quantity, $this->decimal($variantLines->sum('quantity')));
                $this->inventoryBalanceService->releaseReservation($stock, $toRelease);
            }
        }
    }

    private function reservationWarehouseId(InventoryOperation $operation): int
    {
        $warehouseId = $operation->source_warehouse_id;

        return is_int($warehouseId) ? $warehouseId : 0;
    }

    private function recordMovement(InventoryOperationLine $line, InventoryOperation $operation, int $warehouseId, float $quantity, ?User $actor): void
    {
        InventoryMovement::query()->forceCreate([
            'product_variant_id' => $line->product_variant_id,
            'warehouse_id' => $warehouseId,
            'movement_type' => $this->movementTypeFor($operation->operation_type),
            'quantity' => $quantity,
            'source_type' => 'inventory_operation',
            'source_id' => $operation->getKey(),
            'serialized_inventory_unit_id' => $line->serialized_inventory_unit_id,
            'inventory_lot_id' => $line->inventory_lot_id,
            'package_id' => $line->package_id,
            'status' => 'confirmed',
            'created_by' => $actor?->getKey(),
            'notes' => $operation->notes,
        ]);
    }

    private function movementTypeFor(OperationType $type): MovementType
    {
        return match ($type) {
            OperationType::Receipt => MovementType::Receipt,
            OperationType::Delivery => MovementType::Sale,
            OperationType::InternalTransfer => MovementType::Transfer,
        };
    }

    private function requireWarehouse(?int $warehouseId): int
    {
        if (! is_int($warehouseId)) {
            throw new DomainException(__('admin.inventory.operation.errors.illegal_transition', ['from' => '', 'to' => '']));
        }

        return $warehouseId;
    }

    private function decimal(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function nextOperationNumber(): string
    {
        $maxNumber = InventoryOperation::query()->whereNotNull('operation_number')->lockForUpdate()->max('operation_number');

        return sprintf('OP-%06d', is_string($maxNumber) ? (int) mb_substr($maxNumber, 3) + 1 : 1);
    }
}
