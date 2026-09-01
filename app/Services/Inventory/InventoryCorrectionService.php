<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryPostingCommand;
use App\Enums\InventoryCorrectionStatus;
use App\Enums\InventoryCorrectionType;
use App\Enums\InventoryPostingBalanceMode;
use App\Enums\MovementType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryCorrection;
use App\Models\InventoryCorrectionLine;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class InventoryCorrectionService
{
    private const int QUANTITY_SCALE = 6;

    public function __construct(
        private InventoryPostingService $inventoryPostingService,
        private InventoryAlertService $inventoryAlertService,
    ) {}

    public function createReceiptCorrection(
        User $actor,
        InventoryOperation $receipt,
        string $reason,
        ?string $notes = null,
    ): InventoryCorrection {
        return DB::transaction(function () use ($actor, $receipt, $reason, $notes): InventoryCorrection {
            $lockedReceipt = InventoryOperation::query()
                ->lockForUpdate()
                ->findOrFail($receipt->getKey());

            if (
                $lockedReceipt->operation_type !== OperationType::Receipt
                || $lockedReceipt->stage !== OperationStage::Done
            ) {
                throw new DomainException('Receipt corrections require a completed receipt operation.');
            }

            $reason = mb_trim($reason);

            if ($reason === '') {
                throw new DomainException('A receipt correction requires a reason.');
            }

            return InventoryCorrection::query()->forceCreate([
                'correction_number' => $this->nextCorrectionNumber(),
                'correction_type' => InventoryCorrectionType::Receipt,
                'status' => InventoryCorrectionStatus::Draft,
                'original_inventory_operation_id' => $lockedReceipt->getKey(),
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
        }, attempts: 5);
    }

    public function addReceiptLine(
        InventoryCorrection $correction,
        InventoryOperationLine $receiptLine,
        string $transactionQuantity,
    ): InventoryCorrectionLine {
        return DB::transaction(function () use ($correction, $receiptLine, $transactionQuantity): InventoryCorrectionLine {
            $lockedCorrection = $this->lockedDraft($correction);
            $line = InventoryOperationLine::query()
                ->with('operation')
                ->lockForUpdate()
                ->findOrFail($receiptLine->getKey());

            if (
                $line->inventory_operation_id !== $lockedCorrection->original_inventory_operation_id
                || $line->operation?->operation_type !== OperationType::Receipt
                || $line->operation?->stage !== OperationStage::Done
            ) {
                throw new DomainException('A correction line must reference a line from the original completed receipt.');
            }

            $movement = $this->lockOriginalReceiptMovement($line);
            $snapshot = $this->correctionSnapshot($movement, $transactionQuantity);
            $this->assertLineCanBeCorrected(
                $lockedCorrection,
                $line,
                $movement,
                $snapshot['base_quantity'],
                includeCurrentDraft: true,
            );

            return $lockedCorrection->lines()->create([
                'original_inventory_movement_id' => $movement->getKey(),
                'original_inventory_operation_line_id' => $line->getKey(),
                'product_variant_id' => $movement->product_variant_id,
                'warehouse_id' => $movement->warehouse_id,
                'transaction_quantity' => $snapshot['transaction_quantity'],
                'transaction_unit_id' => $snapshot['transaction_unit_id'],
                'conversion_factor_snapshot' => $snapshot['conversion_factor_snapshot'],
                'base_quantity' => $snapshot['base_quantity'],
                'inventory_lot_id' => $movement->inventory_lot_id,
                'serialized_inventory_unit_id' => $movement->serialized_inventory_unit_id,
            ]);
        }, attempts: 5);
    }

    public function removeLine(InventoryCorrectionLine $line): void
    {
        DB::transaction(function () use ($line): void {
            $locked = InventoryCorrectionLine::query()
                ->with('correction')
                ->lockForUpdate()
                ->findOrFail($line->getKey());

            if (! $locked->correction instanceof InventoryCorrection || ! $locked->correction->isDraft()) {
                throw new DomainException('Correction lines can only be removed from a draft correction.');
            }

            $locked->delete();
        }, attempts: 5);
    }

    public function post(InventoryCorrection $correction, User $actor): InventoryCorrection
    {
        return DB::transaction(function () use ($correction, $actor): InventoryCorrection {
            $locked = InventoryCorrection::query()
                ->lockForUpdate()
                ->findOrFail($correction->getKey());

            if ($locked->isPosted()) {
                return $locked->refresh();
            }

            if ($locked->isCancelled()) {
                throw new DomainException('A cancelled inventory correction cannot be posted.');
            }

            if (
                $locked->correction_type !== InventoryCorrectionType::Receipt
                || ! $locked->isDraft()
            ) {
                throw new DomainException('Only a draft receipt correction can be posted.');
            }

            $operation = InventoryOperation::query()
                ->lockForUpdate()
                ->findOrFail($locked->original_inventory_operation_id);

            if (
                $operation->operation_type !== OperationType::Receipt
                || $operation->stage !== OperationStage::Done
            ) {
                throw new DomainException('The original receipt must remain a completed immutable operation.');
            }

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();

            if ($lines->isEmpty()) {
                throw new DomainException('A correction must contain at least one line.');
            }

            $commands = [];

            foreach ($lines as $line) {
                $operationLine = InventoryOperationLine::query()
                    ->with('operation')
                    ->lockForUpdate()
                    ->findOrFail($line->original_inventory_operation_line_id);
                $movement = InventoryMovement::query()
                    ->lockForUpdate()
                    ->findOrFail($line->original_inventory_movement_id);

                $this->assertLineCanBeCorrected(
                    $locked,
                    $operationLine,
                    $movement,
                    (string) $line->base_quantity,
                    includeCurrentDraft: false,
                );

                $negativeBase = bcsub('0', (string) $line->base_quantity, self::QUANTITY_SCALE);
                $hasSerial = is_int($line->serialized_inventory_unit_id);
                $supplierId = $operation->supplier_id;

                $serialStatus = null;
                $custodyType = null;
                $custodyReferenceType = null;
                $custodyReferenceId = null;

                if ($hasSerial) {
                    if (is_int($supplierId)) {
                        $serialStatus = SerializedInventoryUnitStatus::ReturnedToSupplier;
                        $custodyType = SerializedCustodyType::Supplier;
                        $custodyReferenceType = 'supplier';
                        $custodyReferenceId = $supplierId;
                    } else {
                        $serialStatus = SerializedInventoryUnitStatus::Unknown;
                        $custodyType = SerializedCustodyType::Unknown;
                        $custodyReferenceType = 'inventory_correction';
                        $custodyReferenceId = $locked->getKey();
                    }
                }

                $commands[] = new InventoryPostingCommand(
                    productVariantId: (int) $line->product_variant_id,
                    warehouseId: (int) $line->warehouse_id,
                    onHandBaseQuantityDelta: $negativeBase,
                    reservedBaseQuantityDelta: '0.000000',
                    damagedBaseQuantityDelta: '0.000000',
                    movementType: MovementType::Correction,
                    movementBaseQuantityDelta: $negativeBase,
                    sourceType: 'inventory_correction',
                    sourceId: (int) $locked->getKey(),
                    actorId: (int) $actor->getKey(),
                    notes: $locked->reason,
                    serializedInventoryUnitId: $line->serialized_inventory_unit_id,
                    idempotencyKey: sprintf(
                        'inventory-correction:%d:line:%d:post',
                        $locked->getKey(),
                        $line->getKey(),
                    ),
                    balanceMode: InventoryPostingBalanceMode::RequireExisting,
                    inventoryLotId: $line->inventory_lot_id,
                    sourceLineType: 'inventory_correction_line',
                    sourceLineId: (int) $line->getKey(),
                    reversalOfMovementId: (int) $movement->getKey(),
                    transactionQuantity: (string) $line->transaction_quantity,
                    transactionUnitId: (int) $line->transaction_unit_id,
                    conversionFactorSnapshot: (string) $line->conversion_factor_snapshot,
                    baseQuantityDelta: $negativeBase,
                    lotOnHandBaseQuantityDelta: $line->inventory_lot_id === null
                        ? null
                        : $negativeBase,
                    serializedTargetStatus: $serialStatus,
                    serializedWarehouseSpecified: $hasSerial,
                    serializedTargetCustodyType: $custodyType,
                    serializedTargetCustodyReferenceType: $custodyReferenceType,
                    serializedTargetCustodyReferenceId: $custodyReferenceId,
                    stockCondition: StockCondition::Saleable,
                    serializedTargetStockCondition: $hasSerial ? StockCondition::Saleable : null,
                );
            }

            $results = $this->inventoryPostingService->postMany($commands);
            $postingsByLineId = [];

            foreach ($results as $posting) {
                $movement = $posting->movement;

                if (
                    $movement->source_line_type !== 'inventory_correction_line'
                    || ! is_int($movement->source_line_id)
                ) {
                    throw new DomainException('Every correction posting must retain correction-line provenance.');
                }

                $postingsByLineId[$movement->source_line_id] = $posting;
            }

            foreach ($lines as $line) {
                $lineId = $line->getKey();
                $posting = is_int($lineId) ? ($postingsByLineId[$lineId] ?? null) : null;

                if ($posting === null) {
                    throw new DomainException('Every correction line must receive one compensating movement.');
                }

                $line->forceFill([
                    'posted_base_quantity' => $line->base_quantity,
                    'posted_inventory_movement_id' => $posting->movement->getKey(),
                ])->save();

                $this->inventoryAlertService->syncStock($posting->stock);
            }

            $locked->forceFill([
                'status' => InventoryCorrectionStatus::Posted,
                'posted_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'original_inventory_operation_id' => $operation->getKey(),
                    'line_count' => $lines->count(),
                ])
                ->log('inventory.correction.posted');

            return $locked->refresh();
        }, attempts: 5);
    }

    public function cancel(
        InventoryCorrection $correction,
        User $actor,
        string $reason,
    ): InventoryCorrection {
        return DB::transaction(function () use ($correction, $actor, $reason): InventoryCorrection {
            $locked = InventoryCorrection::query()
                ->lockForUpdate()
                ->findOrFail($correction->getKey());

            if (! $locked->isDraft()) {
                throw new DomainException('Only a draft inventory correction can be cancelled.');
            }

            $reason = mb_trim($reason);

            if ($reason === '') {
                throw new DomainException('Cancelling an inventory correction requires a reason.');
            }

            $locked->forceFill([
                'status' => InventoryCorrectionStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'reason' => $reason,
                ])
                ->log('inventory.correction.cancelled');

            return $locked->refresh();
        }, attempts: 5);
    }

    private function lockedDraft(InventoryCorrection $correction): InventoryCorrection
    {
        $locked = InventoryCorrection::query()
            ->lockForUpdate()
            ->findOrFail($correction->getKey());

        if (! $locked->isDraft()) {
            throw new DomainException('Correction lines can only be changed while the correction is a draft.');
        }

        return $locked;
    }

    private function lockOriginalReceiptMovement(InventoryOperationLine $line): InventoryMovement
    {
        $movement = InventoryMovement::query()
            ->where('movement_type', MovementType::Receipt->value)
            ->where('source_type', 'inventory_operation')
            ->where('source_id', $line->inventory_operation_id)
            ->where('source_line_type', 'inventory_operation_line')
            ->where('source_line_id', $line->getKey())
            ->lockForUpdate()
            ->first();

        if (! $movement instanceof InventoryMovement) {
            throw new DomainException('The canonical receipt movement cannot be resolved for this correction line.');
        }

        return $movement;
    }

    private function assertLineCanBeCorrected(
        InventoryCorrection $correction,
        InventoryOperationLine $operationLine,
        InventoryMovement $movement,
        string $baseQuantity,
        bool $includeCurrentDraft,
    ): void {
        if (
            $operationLine->inventory_operation_id !== $correction->original_inventory_operation_id
            || $movement->movement_type !== MovementType::Receipt
            || $movement->source_type !== 'inventory_operation'
            || $movement->source_id !== $correction->original_inventory_operation_id
            || $movement->source_line_type !== 'inventory_operation_line'
            || $movement->source_line_id !== $operationLine->getKey()
            || $movement->product_variant_id !== $operationLine->product_variant_id
        ) {
            throw new DomainException('Correction provenance no longer matches the original receipt movement.');
        }

        $originalBase = $this->movementPositiveBaseQuantity($movement);
        $posted = $this->postedCorrectionBaseQuantity($movement);

        $drafted = '0.000000';

        if ($includeCurrentDraft) {
            $drafted = $this->decimal((string) $correction->lines()
                ->where('original_inventory_movement_id', $movement->getKey())
                ->sum('base_quantity'));
        }

        $remaining = bcsub(
            bcsub($originalBase, $posted, self::QUANTITY_SCALE),
            $drafted,
            self::QUANTITY_SCALE,
        );

        if (
            bccomp($baseQuantity, '0', self::QUANTITY_SCALE) !== 1
            || bccomp($baseQuantity, $remaining, self::QUANTITY_SCALE) === 1
        ) {
            throw new DomainException('The correction quantity exceeds the remaining correctable receipt quantity.');
        }

        if (
            $movement->stock_condition_to !== null
            && $movement->stock_condition_to !== StockCondition::Saleable
        ) {
            throw new DomainException('Receipt correction currently supports saleable receipt postings only.');
        }

        if (is_int($movement->serialized_inventory_unit_id)) {
            if (bccomp($baseQuantity, '1.000000', self::QUANTITY_SCALE) !== 0) {
                throw new DomainException('A serialized receipt correction must reverse exactly one unit.');
            }

            $unit = SerializedInventoryUnit::query()
                ->lockForUpdate()
                ->find($movement->serialized_inventory_unit_id);

            if (
                ! $unit instanceof SerializedInventoryUnit
                || $unit->product_variant_id !== $movement->product_variant_id
                || $unit->warehouse_id !== $movement->warehouse_id
                || $unit->status !== SerializedInventoryUnitStatus::Available
                || $unit->custody_type !== SerializedCustodyType::Warehouse
                || $unit->stock_condition !== StockCondition::Saleable
                || $unit->inventory_lot_id !== $movement->inventory_lot_id
            ) {
                throw new DomainException(
                    'The serialized unit is no longer in the original receipt allocation and cannot be corrected destructively.',
                );
            }
        }
    }

    /**
     * @return array{
     *   transaction_quantity:numeric-string,
     *   transaction_unit_id:int,
     *   conversion_factor_snapshot:numeric-string,
     *   base_quantity:numeric-string
     * }
     */
    private function correctionSnapshot(
        InventoryMovement $movement,
        string $transactionQuantity,
    ): array {
        $factor = $movement->conversion_factor_snapshot;
        $unitId = $movement->transaction_unit_id;

        if (
            ! is_string($factor)
            || ! is_int($unitId)
            || bccomp($factor, '0', self::QUANTITY_SCALE) !== 1
        ) {
            throw new DomainException(
                'The original receipt movement has no complete UOM snapshot and cannot be corrected automatically.',
            );
        }

        $quantity = $this->positiveDecimal($transactionQuantity);
        $base = bcmul($quantity, $factor, 12);

        return [
            'transaction_quantity' => bcadd($quantity, '0', self::QUANTITY_SCALE),
            'transaction_unit_id' => $unitId,
            'conversion_factor_snapshot' => bcadd($factor, '0', self::QUANTITY_SCALE),
            'base_quantity' => bcadd($base, '0', self::QUANTITY_SCALE),
        ];
    }

    /** @return numeric-string */
    private function movementPositiveBaseQuantity(InventoryMovement $movement): string
    {
        $value = $movement->base_quantity_delta ?? $movement->quantity;

        if (! is_string($value) || bccomp($value, '0', self::QUANTITY_SCALE) !== 1) {
            throw new DomainException('The original receipt movement does not contain a positive base quantity.');
        }

        return bcadd($value, '0', self::QUANTITY_SCALE);
    }

    /** @return numeric-string */
    private function postedCorrectionBaseQuantity(InventoryMovement $movement): string
    {
        return $this->decimal((string) InventoryCorrectionLine::query()
            ->where('original_inventory_movement_id', $movement->getKey())
            ->whereHas(
                'correction',
                fn ($query) => $query->where('status', InventoryCorrectionStatus::Posted->value),
            )
            ->sum('posted_base_quantity'));
    }

    /** @return numeric-string */
    private function positiveDecimal(string $value): string
    {
        if (
            ! is_numeric($value)
            || preg_match('/^\d+(?:\.\d{1,6})?$/D', $value) !== 1
            || bccomp($value, '0', self::QUANTITY_SCALE) !== 1
        ) {
            throw new DomainException(
                'Correction quantities must be positive exact decimals with at most six decimal places.',
            );
        }

        return $value;
    }

    /** @return numeric-string */
    private function decimal(string $value): string
    {
        if (! is_numeric($value)) {
            throw new DomainException('Correction quantities must be numeric.');
        }

        return bcadd($value, '0', self::QUANTITY_SCALE);
    }

    private function nextCorrectionNumber(): string
    {
        $max = InventoryCorrection::query()
            ->whereNotNull('correction_number')
            ->lockForUpdate()
            ->max('correction_number');

        return sprintf(
            'COR-%06d',
            is_string($max) ? ((int) mb_substr($max, 4)) + 1 : 1,
        );
    }
}
