<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\MovementType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
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
        private AuditLogger $auditLogger,
        private InventoryBalanceService $inventoryBalanceService,
    ) {}

    /**
     * Reserves outbound quantity and moves Draft/Waiting → Ready, or holds at Waiting when a
     * source warehouse cannot cover the required quantity (FR-004, FR-005).
     *
     * @throws DomainException
     */
    public function markReady(InventoryOperation $operation): InventoryOperation
    {
        return DB::transaction(function () use ($operation): InventoryOperation {
            $locked = $this->lock($operation);
            $this->guardStageIsOneOf($locked, [OperationStage::Draft, OperationStage::Waiting]);

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();

            if ($lines->isEmpty()) {
                throw new DomainException(__('admin.inventory.operation.errors.no_lines'));
            }

            $this->assertVariantsAreOperational($lines);
            $this->assertQuantityPrecision($lines);
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

            foreach ($lines->groupBy('product_variant_id') as $productVariantId => $variantLines) {
                if (! is_numeric($productVariantId)) {
                    continue;
                }

                $stock = InventoryStock::query()
                    ->where('product_variant_id', $productVariantId)
                    ->where('warehouse_id', $sourceWarehouseId)
                    ->lockForUpdate()
                    ->first();

                if ($stock instanceof InventoryStock) {
                    $this->inventoryBalanceService->reserve($stock, $this->decimal($variantLines->sum('quantity')));
                }
            }

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
    public function complete(InventoryOperation $operation, User $actor): InventoryOperation
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

            return $this->transitionTo($locked, OperationStage::Done);
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

                foreach ($lines as $line) {
                    $stock = $this->inventoryBalanceService->receive($line->product_variant_id, $sourceWarehouseId, (float) $line->quantity);
                    $this->recordMovement($line, $locked, $sourceWarehouseId, (float) $line->quantity, $actor);
                    $stock->refresh();
                }
            } elseif ($locked->stage === OperationStage::Ready) {
                $this->releaseReservations($lines, $this->reservationWarehouseId($locked));
            }

            $locked->forceFill(['canceled_at' => now(), 'notes' => mb_trim(($locked->notes ?? '').' '.$reason)]);

            $result = $this->transitionTo($locked, OperationStage::Canceled);

            $this->auditLogger->log(
                action: 'inventory.operation.canceled',
                entity: $result,
                oldValues: ['stage' => $locked->getOriginal('stage')],
                newValues: ['stage' => OperationStage::Canceled->value, 'reason' => $reason],
                actor: $actor,
            );

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
        ])->save();

        return $operation->refresh();
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     *
     * @throws DomainException
     */
    private function assertVariantsAreOperational(Collection $lines): void
    {
        foreach ($lines->pluck('product_variant_id')->unique() as $productVariantId) {
            /** @var ProductVariant $variant */
            $variant = ProductVariant::query()->with('product')->lockForUpdate()->findOrFail($productVariantId);

            if (! $variant->isOperational()) {
                throw new DomainException(__('admin.inventory.operation.errors.inactive_variant'));
            }
        }
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     *
     * @throws DomainException
     */
    private function assertQuantityPrecision(Collection $lines): void
    {
        foreach ($lines as $line) {
            $unit = $line->unit;

            if ($unit !== null && ! $unit->allows_decimal && fmod((float) $line->quantity, 1.0) !== 0.0) {
                throw new DomainException(__('admin.inventory.operation.errors.invalid_quantity_precision'));
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
     *
     * @throws DomainException
     */
    private function assertPackagesBelongToWarehouse(Collection $lines, int $warehouseId): void
    {
        foreach ($lines as $line) {
            if (! Package::belongsToWarehouse($line->package_id, $warehouseId)) {
                throw new DomainException(__('admin.package.errors.location_mismatch'));
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
    private function fulfillReservationAndLeave(Collection $lines, int $warehouseId, InventoryOperation $operation, User $actor): void
    {
        $this->releaseReservations($lines, $warehouseId);

        foreach ($lines as $line) {
            $this->inventoryBalanceService->transferOut($line->product_variant_id, $warehouseId, (float) $line->quantity);
            $this->recordMovement($line, $operation, $warehouseId, -(float) $line->quantity, $actor);
        }
    }

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    private function receiveLines(Collection $lines, int $warehouseId, InventoryOperation $operation, User $actor): void
    {
        foreach ($lines as $line) {
            $this->inventoryBalanceService->receive($line->product_variant_id, $warehouseId, (float) $line->quantity);
            $this->recordMovement($line, $operation, $warehouseId, (float) $line->quantity, $actor);
        }
    }

    /** @param Collection<int, InventoryOperationLine> $lines */
    private function movePackagesWithReceivedLines(Collection $lines, int $warehouseId): void
    {
        foreach ($lines->whereNotNull('package_id')->unique('package_id') as $line) {
            $package = Package::query()->lockForUpdate()->find($line->package_id);

            if ($package instanceof Package) {
                $package->moveWithRecordedGoods($warehouseId, $line->warehouse_location_id);
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

    private function recordMovement(InventoryOperationLine $line, InventoryOperation $operation, int $warehouseId, float $quantity, User $actor): void
    {
        InventoryMovement::query()->forceCreate([
            'product_variant_id' => $line->product_variant_id,
            'warehouse_id' => $warehouseId,
            'warehouse_location_id' => $line->warehouse_location_id,
            'movement_type' => $this->movementTypeFor($operation->operation_type),
            'quantity' => $quantity,
            'source_type' => 'inventory_operation',
            'source_id' => $operation->getKey(),
            'serialized_inventory_unit_id' => $line->serialized_inventory_unit_id,
            'inventory_lot_id' => $line->inventory_lot_id,
            'package_id' => $line->package_id,
            'status' => 'confirmed',
            'created_by' => $actor->getKey(),
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
