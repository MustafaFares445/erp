<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryPostingCommand;
use App\Data\Inventory\InventoryPostingResult;
use App\Enums\ConditionChangeReason;
use App\Enums\InventoryCorrectionStatus;
use App\Enums\InventoryCorrectionType;
use App\Enums\InventoryPostingBalanceMode;
use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
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
use App\Models\InventoryReturnLine;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
            $receiptKey = $receipt->getKey();

            if (! is_int($receiptKey)) {
                throw new \LogicException('Inventory operation identifiers must be integers.');
            }

            $lockedReceipt = InventoryOperation::query()
                ->lockForUpdate()
                ->findOrFail($receiptKey);

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
            $lockedCorrection = $this->lockedDraftOfType($correction, InventoryCorrectionType::Receipt);
            $receiptLineKey = $receiptLine->getKey();

            if (! is_int($receiptLineKey)) {
                throw new \LogicException('Inventory operation line identifiers must be integers.');
            }

            $line = InventoryOperationLine::query()
                ->with('operation')
                ->lockForUpdate()
                ->findOrFail($receiptLineKey);

            $operation = $line->operation;

            if (
                $line->inventory_operation_id !== $lockedCorrection->original_inventory_operation_id
                || ! $operation instanceof InventoryOperation
                || $operation->operation_type !== OperationType::Receipt
                || $operation->stage !== OperationStage::Done
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

    /**
     * Opens a correction against a completed delivery (WP-2.11, GAP-BW-02).
     *
     * A delivery correction is explicitly not a customer return: a `$correctionReason` of
     * {@see ConditionChangeReason::CustomerReturnInspection} signals that the goods physically
     * came back, which is a customer return's evidence to carry, not a correction's — so it is
     * refused here with a message pointing at the return path.
     */
    public function createDeliveryCorrection(
        User $actor,
        InventoryOperation $delivery,
        ConditionChangeReason $correctionReason,
        string $reason,
        ?string $notes = null,
    ): InventoryCorrection {
        return DB::transaction(function () use ($actor, $delivery, $correctionReason, $reason, $notes): InventoryCorrection {
            $deliveryKey = $delivery->getKey();

            if (! is_int($deliveryKey)) {
                throw new \LogicException('Inventory operation identifiers must be integers.');
            }

            $lockedDelivery = InventoryOperation::query()
                ->lockForUpdate()
                ->findOrFail($deliveryKey);

            if (
                $lockedDelivery->operation_type !== OperationType::Delivery
                || $lockedDelivery->stage !== OperationStage::Done
            ) {
                throw new DomainException('Delivery corrections require a completed delivery operation.');
            }

            if ($correctionReason === ConditionChangeReason::CustomerReturnInspection) {
                throw new DomainException(
                    'Goods that physically came back from the customer must be recorded through a customer return, not a delivery correction.',
                );
            }

            $reason = mb_trim($reason);

            if ($reason === '') {
                throw new DomainException('A delivery correction requires a reason.');
            }

            return InventoryCorrection::query()->forceCreate([
                'correction_number' => $this->nextCorrectionNumber(),
                'correction_type' => InventoryCorrectionType::Delivery,
                'status' => InventoryCorrectionStatus::Draft,
                'original_inventory_operation_id' => $lockedDelivery->getKey(),
                'correction_reason' => $correctionReason,
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
        }, attempts: 5);
    }

    /**
     * Adds a correction line against one line of the original delivery. The correctable
     * quantity is capped at delivered minus already-corrected minus already-**returned** — the
     * WP-1.3 link between a customer return and its originating delivery lets the two documents
     * see each other, so a customer return posted against the same delivery line shrinks what a
     * correction may still touch.
     */
    public function addDeliveryLine(
        InventoryCorrection $correction,
        InventoryOperationLine $deliveryLine,
        string $transactionQuantity,
    ): InventoryCorrectionLine {
        return DB::transaction(function () use ($correction, $deliveryLine, $transactionQuantity): InventoryCorrectionLine {
            $lockedCorrection = $this->lockedDraftOfType($correction, InventoryCorrectionType::Delivery);
            $deliveryLineKey = $deliveryLine->getKey();

            if (! is_int($deliveryLineKey)) {
                throw new \LogicException('Inventory operation line identifiers must be integers.');
            }

            $line = InventoryOperationLine::query()
                ->with('operation')
                ->lockForUpdate()
                ->findOrFail($deliveryLineKey);

            $operation = $line->operation;

            if (
                $line->inventory_operation_id !== $lockedCorrection->original_inventory_operation_id
                || ! $operation instanceof InventoryOperation
                || $operation->operation_type !== OperationType::Delivery
                || $operation->stage !== OperationStage::Done
            ) {
                throw new DomainException('A correction line must reference a line from the original completed delivery.');
            }

            $movement = $this->lockOriginalDeliveryMovement($line);
            $snapshot = $this->correctionSnapshot($movement, $transactionQuantity);
            $this->assertDeliveryLineCanBeCorrected(
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

    /**
     * Opens a correction against a completed internal transfer (WP-2.11, GAP-BW-02).
     *
     * A transfer stuck at {@see OperationStage::PartiallyReceived} is refused: its remedy is the
     * transfer-receipt shortage workflow ({@see InventoryOperationService::receiveTransfer()}),
     * not a correction, because a correction only ever compensates a *completed* posting.
     *
     * `$targetWarehouseId`, when given, names the warehouse the stock should actually have
     * landed at; it must differ from the transfer's own (wrong) destination. Omitted, a
     * correction simply reverses the transfer back to its source.
     */
    public function createTransferCorrection(
        User $actor,
        InventoryOperation $transfer,
        ConditionChangeReason $correctionReason,
        string $reason,
        ?int $targetWarehouseId = null,
        ?string $notes = null,
    ): InventoryCorrection {
        return DB::transaction(function () use (
            $actor,
            $transfer,
            $correctionReason,
            $reason,
            $targetWarehouseId,
            $notes,
        ): InventoryCorrection {
            $transferKey = $transfer->getKey();

            if (! is_int($transferKey)) {
                throw new \LogicException('Inventory operation identifiers must be integers.');
            }

            $lockedTransfer = InventoryOperation::query()
                ->lockForUpdate()
                ->findOrFail($transferKey);

            if ($lockedTransfer->operation_type !== OperationType::InternalTransfer) {
                throw new DomainException('Transfer corrections require a completed internal transfer operation.');
            }

            if ($lockedTransfer->stage === OperationStage::PartiallyReceived) {
                throw new DomainException(
                    'A partially received transfer must be resolved through the transfer receipt shortage workflow before it can be corrected.',
                );
            }

            if ($lockedTransfer->stage !== OperationStage::Done) {
                throw new DomainException('Transfer corrections require a completed internal transfer operation.');
            }

            $lockedTargetWarehouseId = null;

            if ($targetWarehouseId !== null) {
                $targetWarehouse = Warehouse::query()->lockForUpdate()->find($targetWarehouseId);

                if (! $targetWarehouse instanceof Warehouse || ! $targetWarehouse->is_active) {
                    throw new DomainException('The correction target warehouse must be an active warehouse.');
                }

                $targetWarehouseKey = $targetWarehouse->getKey();

                if (! is_int($targetWarehouseKey)) {
                    throw new \LogicException('Inventory operation identifiers must be integers.');
                }

                if ($targetWarehouseKey === $lockedTransfer->destination_warehouse_id) {
                    throw new DomainException('The correction target warehouse must differ from the original destination.');
                }

                $lockedTargetWarehouseId = $targetWarehouseKey;
            }

            $reason = mb_trim($reason);

            if ($reason === '') {
                throw new DomainException('A transfer correction requires a reason.');
            }

            return InventoryCorrection::query()->forceCreate([
                'correction_number' => $this->nextCorrectionNumber(),
                'correction_type' => InventoryCorrectionType::Transfer,
                'status' => InventoryCorrectionStatus::Draft,
                'original_inventory_operation_id' => $lockedTransfer->getKey(),
                'correction_reason' => $correctionReason,
                'target_warehouse_id' => $lockedTargetWarehouseId,
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
        }, attempts: 5);
    }

    /**
     * Adds a correction line against one line of the original internal transfer, referencing the
     * canonical receive-side movement at the transfer's (possibly wrong) destination warehouse.
     */
    public function addTransferLine(
        InventoryCorrection $correction,
        InventoryOperationLine $transferLine,
        string $transactionQuantity,
    ): InventoryCorrectionLine {
        return DB::transaction(function () use ($correction, $transferLine, $transactionQuantity): InventoryCorrectionLine {
            $lockedCorrection = $this->lockedDraftOfType($correction, InventoryCorrectionType::Transfer);
            $transferLineKey = $transferLine->getKey();

            if (! is_int($transferLineKey)) {
                throw new \LogicException('Inventory operation line identifiers must be integers.');
            }

            $line = InventoryOperationLine::query()
                ->with('operation')
                ->lockForUpdate()
                ->findOrFail($transferLineKey);

            $operation = $line->operation;

            if (
                $line->inventory_operation_id !== $lockedCorrection->original_inventory_operation_id
                || ! $operation instanceof InventoryOperation
                || $operation->operation_type !== OperationType::InternalTransfer
                || $operation->stage !== OperationStage::Done
            ) {
                throw new DomainException('A correction line must reference a line from the original completed internal transfer.');
            }

            $movement = $this->lockOriginalTransferReceiveMovement($line, $operation);
            $snapshot = $this->correctionSnapshot($movement, $transactionQuantity);
            $this->assertTransferLineCanBeCorrected(
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
            $lineKey = $line->getKey();

            if (! is_int($lineKey)) {
                throw new \LogicException('Inventory correction line identifiers must be integers.');
            }

            $locked = InventoryCorrectionLine::query()
                ->with('correction')
                ->lockForUpdate()
                ->findOrFail($lineKey);

            if (! $locked->correction instanceof InventoryCorrection || ! $locked->correction->isDraft()) {
                throw new DomainException('Correction lines can only be removed from a draft correction.');
            }

            $locked->delete();
        }, attempts: 5);
    }

    public function post(InventoryCorrection $correction, User $actor): InventoryCorrection
    {
        return DB::transaction(function () use ($correction, $actor): InventoryCorrection {
            $correctionKey = $correction->getKey();

            if (! is_int($correctionKey)) {
                throw new \LogicException('Inventory correction identifiers must be integers.');
            }

            $locked = InventoryCorrection::query()
                ->lockForUpdate()
                ->findOrFail($correctionKey);

            if ($locked->isPosted()) {
                return $locked->refresh();
            }

            if ($locked->isCancelled()) {
                throw new DomainException('A cancelled inventory correction cannot be posted.');
            }

            if (! $locked->isDraft()) {
                throw new DomainException('Only a draft inventory correction can be posted.');
            }

            $correctionType = $locked->correction_type;

            $operation = InventoryOperation::query()
                ->lockForUpdate()
                ->findOrFail($locked->original_inventory_operation_id);

            if (
                $operation->operation_type !== $correctionType->originOperationType()
                || $operation->stage !== OperationStage::Done
            ) {
                throw new DomainException('The original operation must remain a completed immutable operation.');
            }

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();

            if ($lines->isEmpty()) {
                throw new DomainException('A correction must contain at least one line.');
            }

            $lockedKey = $locked->getKey();
            $actorKey = $actor->getKey();

            if (! is_int($lockedKey) || ! is_int($actorKey)) {
                throw new \LogicException('Inventory correction identifiers must be integers.');
            }

            $commands = match ($correctionType) {
                InventoryCorrectionType::Receipt => $this->receiptPostingCommands($locked, $lines, $operation, $actorKey),
                InventoryCorrectionType::Delivery => $this->deliveryPostingCommands($locked, $lines, $operation, $actorKey),
                InventoryCorrectionType::Transfer => $this->transferPostingCommands($locked, $lines, $operation, $actorKey),
            };

            $results = $this->inventoryPostingService->postMany($commands);

            /** @var array<int, list<InventoryPostingResult>> $postingsByLineId */
            $postingsByLineId = [];

            foreach ($results as $posting) {
                $movement = $posting->movement;

                if (
                    $movement->source_line_type !== 'inventory_correction_line'
                    || ! is_int($movement->source_line_id)
                ) {
                    throw new DomainException('Every correction posting must retain correction-line provenance.');
                }

                $postingsByLineId[$movement->source_line_id][] = $posting;
            }

            foreach ($lines as $line) {
                $lineId = $line->getKey();
                $postings = is_int($lineId) ? ($postingsByLineId[$lineId] ?? []) : [];

                if ($postings === []) {
                    throw new DomainException('Every correction line must receive one compensating movement.');
                }

                // A transfer correction with a redirected destination produces two movements per
                // line (one reversing the wrong warehouse, one establishing the right one); the
                // one that shares the line's own warehouse is the compensating "reversal" leg and
                // is what the line's evidence pointer records — mirroring the receipt/delivery
                // single-movement case, where that is simply the only posting.
                $primary = null;

                foreach ($postings as $posting) {
                    $this->inventoryAlertService->syncStock($posting->stock);

                    if ($posting->movement->warehouse_id === $line->warehouse_id) {
                        $primary = $posting;
                    }
                }

                $primary ??= $postings[0];

                $line->forceFill([
                    'posted_base_quantity' => $line->base_quantity,
                    'posted_inventory_movement_id' => $primary->movement->getKey(),
                ])->save();
            }

            $locked->forceFill([
                'status' => InventoryCorrectionStatus::Posted,
                'posted_at' => now(),
                'updated_by' => $actorKey,
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
            $correctionKey = $correction->getKey();

            if (! is_int($correctionKey)) {
                throw new \LogicException('Inventory correction identifiers must be integers.');
            }

            $locked = InventoryCorrection::query()
                ->lockForUpdate()
                ->findOrFail($correctionKey);

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
        $correctionKey = $correction->getKey();

        if (! is_int($correctionKey)) {
            throw new \LogicException('Inventory correction identifiers must be integers.');
        }

        $locked = InventoryCorrection::query()
            ->lockForUpdate()
            ->findOrFail($correctionKey);

        if (! $locked->isDraft()) {
            throw new DomainException('Correction lines can only be changed while the correction is a draft.');
        }

        return $locked;
    }

    private function lockedDraftOfType(
        InventoryCorrection $correction,
        InventoryCorrectionType $type,
    ): InventoryCorrection {
        $locked = $this->lockedDraft($correction);

        if ($locked->correction_type !== $type) {
            throw new DomainException('This correction line action does not match the correction document type.');
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

    private function lockOriginalDeliveryMovement(InventoryOperationLine $line): InventoryMovement
    {
        $movement = InventoryMovement::query()
            ->where('movement_type', MovementType::Sale->value)
            ->where('source_type', 'inventory_operation')
            ->where('source_id', $line->inventory_operation_id)
            ->where('source_line_type', 'inventory_operation_line')
            ->where('source_line_id', $line->getKey())
            ->lockForUpdate()
            ->first();

        if (! $movement instanceof InventoryMovement) {
            throw new DomainException('The canonical delivery movement cannot be resolved for this correction line.');
        }

        return $movement;
    }

    /**
     * Resolves the transfer's receive-side movement at its destination warehouse — the positive
     * leg of the transferOut/transferIn pair a transfer correction reverses. Assumes the line was
     * settled by a single {@see InventoryOperationService::receiveTransfer()} call, matching the
     * only scenario this correction supports (a fully `Done` transfer); a transfer resolved
     * across multiple partial receipts is out of scope.
     */
    private function lockOriginalTransferReceiveMovement(
        InventoryOperationLine $line,
        InventoryOperation $operation,
    ): InventoryMovement {
        $movement = InventoryMovement::query()
            ->where('movement_type', MovementType::Transfer->value)
            ->where('source_type', 'inventory_operation')
            ->where('source_id', $line->inventory_operation_id)
            ->where('source_line_type', 'inventory_operation_line')
            ->where('source_line_id', $line->getKey())
            ->where('warehouse_id', $operation->destination_warehouse_id)
            ->where('quantity', '>', 0)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (! $movement instanceof InventoryMovement) {
            throw new DomainException('The canonical transfer receive movement cannot be resolved for this correction line.');
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

        if (! is_numeric($baseQuantity)) {
            throw new DomainException('Correction quantities must be numeric.');
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
     * The delivery counterpart of {@see self::assertLineCanBeCorrected()}. The correctable
     * quantity additionally subtracts whatever has already been posted through a customer return
     * against the same delivery movement (WP-1.3's linkage), and the serialized guard expects the
     * unit to still be in customer custody rather than warehouse custody.
     */
    private function assertDeliveryLineCanBeCorrected(
        InventoryCorrection $correction,
        InventoryOperationLine $operationLine,
        InventoryMovement $movement,
        string $baseQuantity,
        bool $includeCurrentDraft,
    ): void {
        if (
            $operationLine->inventory_operation_id !== $correction->original_inventory_operation_id
            || $movement->movement_type !== MovementType::Sale
            || $movement->source_type !== 'inventory_operation'
            || $movement->source_id !== $correction->original_inventory_operation_id
            || $movement->source_line_type !== 'inventory_operation_line'
            || $movement->source_line_id !== $operationLine->getKey()
            || $movement->product_variant_id !== $operationLine->product_variant_id
        ) {
            throw new DomainException('Correction provenance no longer matches the original delivery movement.');
        }

        if (! is_numeric($baseQuantity)) {
            throw new DomainException('Correction quantities must be numeric.');
        }

        $originalBase = $this->movementAbsoluteBaseQuantity($movement);
        $posted = $this->postedCorrectionBaseQuantity($movement);
        $returned = $this->postedReturnBaseQuantity($movement);

        $drafted = '0.000000';

        if ($includeCurrentDraft) {
            $drafted = $this->decimal((string) $correction->lines()
                ->where('original_inventory_movement_id', $movement->getKey())
                ->sum('base_quantity'));
        }

        $remaining = bcsub(
            bcsub(
                bcsub($originalBase, $posted, self::QUANTITY_SCALE),
                $returned,
                self::QUANTITY_SCALE,
            ),
            $drafted,
            self::QUANTITY_SCALE,
        );

        if (
            bccomp($baseQuantity, '0', self::QUANTITY_SCALE) !== 1
            || bccomp($baseQuantity, $remaining, self::QUANTITY_SCALE) === 1
        ) {
            throw new DomainException(
                'The correction quantity exceeds the remaining correctable delivery quantity once prior corrections and customer returns are accounted for.',
            );
        }

        if (
            $movement->stock_condition_from !== null
            && $movement->stock_condition_from !== StockCondition::Saleable
        ) {
            throw new DomainException('Delivery correction currently supports saleable delivery postings only.');
        }

        if (is_int($movement->serialized_inventory_unit_id)) {
            if (bccomp($baseQuantity, '1.000000', self::QUANTITY_SCALE) !== 0) {
                throw new DomainException('A serialized delivery correction must reverse exactly one unit.');
            }

            $unit = SerializedInventoryUnit::query()
                ->lockForUpdate()
                ->find($movement->serialized_inventory_unit_id);

            if (
                ! $unit instanceof SerializedInventoryUnit
                || $unit->product_variant_id !== $movement->product_variant_id
                || $unit->status !== SerializedInventoryUnitStatus::Delivered
                || $unit->custody_type !== SerializedCustodyType::Customer
                || $unit->inventory_lot_id !== $movement->inventory_lot_id
            ) {
                throw new DomainException(
                    'The serialized unit is no longer in the original delivery allocation and cannot be corrected destructively — it may already have come back as a customer return.',
                );
            }
        }
    }

    /**
     * The internal-transfer counterpart of {@see self::assertLineCanBeCorrected()}, validated
     * against the receive-side movement at the transfer's destination warehouse.
     */
    private function assertTransferLineCanBeCorrected(
        InventoryCorrection $correction,
        InventoryOperationLine $operationLine,
        InventoryMovement $movement,
        string $baseQuantity,
        bool $includeCurrentDraft,
    ): void {
        if (
            $operationLine->inventory_operation_id !== $correction->original_inventory_operation_id
            || $movement->movement_type !== MovementType::Transfer
            || $movement->source_type !== 'inventory_operation'
            || $movement->source_id !== $correction->original_inventory_operation_id
            || $movement->source_line_type !== 'inventory_operation_line'
            || $movement->source_line_id !== $operationLine->getKey()
            || $movement->product_variant_id !== $operationLine->product_variant_id
        ) {
            throw new DomainException('Correction provenance no longer matches the original transfer receive movement.');
        }

        if (! is_numeric($baseQuantity)) {
            throw new DomainException('Correction quantities must be numeric.');
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
            throw new DomainException('The correction quantity exceeds the remaining correctable transfer quantity.');
        }

        if (
            $movement->stock_condition_to !== null
            && $movement->stock_condition_to !== StockCondition::Saleable
        ) {
            throw new DomainException('Transfer correction currently supports saleable transfer postings only.');
        }

        if (is_int($movement->serialized_inventory_unit_id)) {
            if (bccomp($baseQuantity, '1.000000', self::QUANTITY_SCALE) !== 0) {
                throw new DomainException('A serialized transfer correction must reverse exactly one unit.');
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
                    'The serialized unit is no longer in the original transfer allocation and cannot be corrected destructively.',
                );
            }
        }
    }

    /**
     * @param  Collection<int, InventoryCorrectionLine>  $lines
     * @return list<InventoryPostingCommand>
     */
    private function receiptPostingCommands(
        InventoryCorrection $correction,
        Collection $lines,
        InventoryOperation $operation,
        int $actorKey,
    ): array {
        $correctionKey = $correction->getKey();

        if (! is_int($correctionKey)) {
            throw new \LogicException('Inventory correction identifiers must be integers.');
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

            $movementKey = $movement->getKey();
            $lineKey = $line->getKey();

            if (! is_int($movementKey) || ! is_int($lineKey)) {
                throw new \LogicException('Inventory correction identifiers must be integers.');
            }

            $this->assertLineCanBeCorrected(
                $correction,
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
                    $custodyReferenceId = $correctionKey;
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
                sourceId: $correctionKey,
                actorId: $actorKey,
                notes: $correction->reason,
                serializedInventoryUnitId: $line->serialized_inventory_unit_id,
                idempotencyKey: sprintf(
                    'inventory-correction:%d:line:%d:post',
                    $correctionKey,
                    $lineKey,
                ),
                balanceMode: InventoryPostingBalanceMode::RequireExisting,
                inventoryLotId: $line->inventory_lot_id,
                sourceLineType: 'inventory_correction_line',
                sourceLineId: $lineKey,
                reversalOfMovementId: $movementKey,
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

        return $commands;
    }

    /**
     * Builds the compensating postings for a delivery correction: one positive movement per
     * line, always landing back at the delivery's own source warehouse — the goods never
     * physically left it, so a delivery correction has no destination to choose (unlike a
     * transfer correction's `target_warehouse_id`).
     *
     * @param  Collection<int, InventoryCorrectionLine>  $lines
     * @return list<InventoryPostingCommand>
     */
    private function deliveryPostingCommands(
        InventoryCorrection $correction,
        Collection $lines,
        InventoryOperation $operation,
        int $actorKey,
    ): array {
        $correctionKey = $correction->getKey();

        if (! is_int($correctionKey)) {
            throw new \LogicException('Inventory correction identifiers must be integers.');
        }

        $sourceWarehouseId = $operation->source_warehouse_id;

        if (! is_int($sourceWarehouseId)) {
            throw new DomainException('A delivery correction requires the original delivery to name a source warehouse.');
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

            $movementKey = $movement->getKey();
            $lineKey = $line->getKey();

            if (! is_int($movementKey) || ! is_int($lineKey)) {
                throw new \LogicException('Inventory correction identifiers must be integers.');
            }

            $this->assertDeliveryLineCanBeCorrected(
                $correction,
                $operationLine,
                $movement,
                (string) $line->base_quantity,
                includeCurrentDraft: false,
            );

            $positiveBase = $this->decimal((string) $line->base_quantity);
            $hasSerial = is_int($line->serialized_inventory_unit_id);

            $commands[] = new InventoryPostingCommand(
                productVariantId: (int) $line->product_variant_id,
                warehouseId: $sourceWarehouseId,
                onHandBaseQuantityDelta: $positiveBase,
                reservedBaseQuantityDelta: '0.000000',
                damagedBaseQuantityDelta: '0.000000',
                movementType: MovementType::Correction,
                movementBaseQuantityDelta: $positiveBase,
                sourceType: 'inventory_correction',
                sourceId: $correctionKey,
                actorId: $actorKey,
                notes: $correction->reason,
                serializedInventoryUnitId: $line->serialized_inventory_unit_id,
                idempotencyKey: sprintf(
                    'inventory-correction:%d:line:%d:post',
                    $correctionKey,
                    $lineKey,
                ),
                balanceMode: InventoryPostingBalanceMode::RequireExisting,
                inventoryLotId: $line->inventory_lot_id,
                sourceLineType: 'inventory_correction_line',
                sourceLineId: $lineKey,
                reversalOfMovementId: $movementKey,
                transactionQuantity: (string) $line->transaction_quantity,
                transactionUnitId: (int) $line->transaction_unit_id,
                conversionFactorSnapshot: (string) $line->conversion_factor_snapshot,
                baseQuantityDelta: $positiveBase,
                lotOnHandBaseQuantityDelta: $line->inventory_lot_id === null
                    ? null
                    : $positiveBase,
                serializedTargetStatus: $hasSerial ? SerializedInventoryUnitStatus::Available : null,
                serializedWarehouseSpecified: $hasSerial,
                serializedTargetWarehouseId: $hasSerial ? $sourceWarehouseId : null,
                serializedTargetCustodyType: $hasSerial ? SerializedCustodyType::Warehouse : null,
                serializedTargetCustodyReferenceType: $hasSerial ? 'warehouse' : null,
                serializedTargetCustodyReferenceId: $hasSerial ? $sourceWarehouseId : null,
                stockCondition: StockCondition::Saleable,
                serializedTargetStockCondition: $hasSerial ? StockCondition::Saleable : null,
                serializedInventoryLotSpecified: $hasSerial,
                serializedTargetInventoryLotId: $hasSerial ? $line->inventory_lot_id : null,
            );
        }

        return $commands;
    }

    /**
     * Builds the compensating postings for a transfer correction. Every line always produces a
     * negative "removal" movement at the warehouse the transfer actually (and wrongly) landed
     * at, reversing the receive-side movement; when the correction names a
     * `target_warehouse_id` different from that warehouse (or, absent one, the transfer's own
     * source), it also produces a positive "arrival" movement there. Both are posted together
     * through {@see InventoryPostingService::postMany()} inside the same transaction as this
     * whole `post()` call, so stock is never observably in both places, or in neither.
     *
     * @param  Collection<int, InventoryCorrectionLine>  $lines
     * @return list<InventoryPostingCommand>
     */
    private function transferPostingCommands(
        InventoryCorrection $correction,
        Collection $lines,
        InventoryOperation $operation,
        int $actorKey,
    ): array {
        $correctionKey = $correction->getKey();

        if (! is_int($correctionKey)) {
            throw new \LogicException('Inventory correction identifiers must be integers.');
        }

        $sourceWarehouseId = $operation->source_warehouse_id;

        if (! is_int($sourceWarehouseId)) {
            throw new DomainException('A transfer correction requires the original transfer to name a source warehouse.');
        }

        $targetWarehouseId = $correction->target_warehouse_id ?? $sourceWarehouseId;

        $commands = [];

        foreach ($lines as $line) {
            $operationLine = InventoryOperationLine::query()
                ->with('operation')
                ->lockForUpdate()
                ->findOrFail($line->original_inventory_operation_line_id);
            $movement = InventoryMovement::query()
                ->lockForUpdate()
                ->findOrFail($line->original_inventory_movement_id);

            $movementKey = $movement->getKey();
            $lineKey = $line->getKey();

            if (! is_int($movementKey) || ! is_int($lineKey)) {
                throw new \LogicException('Inventory correction identifiers must be integers.');
            }

            $this->assertTransferLineCanBeCorrected(
                $correction,
                $operationLine,
                $movement,
                (string) $line->base_quantity,
                includeCurrentDraft: false,
            );

            $baseQuantity = $this->decimal((string) $line->base_quantity);
            $negativeBase = bcsub('0', $baseQuantity, self::QUANTITY_SCALE);
            $hasSerial = is_int($line->serialized_inventory_unit_id);
            $wrongWarehouseId = (int) $line->warehouse_id;

            if ($targetWarehouseId === $wrongWarehouseId) {
                throw new DomainException('The transfer correction target warehouse must differ from the warehouse being corrected.');
            }

            $commands[] = new InventoryPostingCommand(
                productVariantId: (int) $line->product_variant_id,
                warehouseId: $wrongWarehouseId,
                onHandBaseQuantityDelta: $negativeBase,
                reservedBaseQuantityDelta: '0.000000',
                damagedBaseQuantityDelta: '0.000000',
                movementType: MovementType::Correction,
                movementBaseQuantityDelta: $negativeBase,
                sourceType: 'inventory_correction',
                sourceId: $correctionKey,
                actorId: $actorKey,
                notes: $correction->reason,
                serializedInventoryUnitId: $line->serialized_inventory_unit_id,
                idempotencyKey: sprintf(
                    'inventory-correction:%d:line:%d:post:remove',
                    $correctionKey,
                    $lineKey,
                ),
                balanceMode: InventoryPostingBalanceMode::RequireExisting,
                inventoryLotId: $line->inventory_lot_id,
                sourceLineType: 'inventory_correction_line',
                sourceLineId: $lineKey,
                reversalOfMovementId: $movementKey,
                transactionQuantity: (string) $line->transaction_quantity,
                transactionUnitId: (int) $line->transaction_unit_id,
                conversionFactorSnapshot: (string) $line->conversion_factor_snapshot,
                baseQuantityDelta: $negativeBase,
                lotOnHandBaseQuantityDelta: $line->inventory_lot_id === null
                    ? null
                    : $negativeBase,
                stockCondition: StockCondition::Saleable,
            );

            $commands[] = new InventoryPostingCommand(
                productVariantId: (int) $line->product_variant_id,
                warehouseId: $targetWarehouseId,
                onHandBaseQuantityDelta: $baseQuantity,
                reservedBaseQuantityDelta: '0.000000',
                damagedBaseQuantityDelta: '0.000000',
                movementType: MovementType::Correction,
                movementBaseQuantityDelta: $baseQuantity,
                sourceType: 'inventory_correction',
                sourceId: $correctionKey,
                actorId: $actorKey,
                notes: $correction->reason,
                serializedInventoryUnitId: $line->serialized_inventory_unit_id,
                idempotencyKey: sprintf(
                    'inventory-correction:%d:line:%d:post:add',
                    $correctionKey,
                    $lineKey,
                ),
                balanceMode: InventoryPostingBalanceMode::CreateIfMissing,
                inventoryLotId: $line->inventory_lot_id,
                sourceLineType: 'inventory_correction_line',
                sourceLineId: $lineKey,
                transactionQuantity: (string) $line->transaction_quantity,
                transactionUnitId: (int) $line->transaction_unit_id,
                conversionFactorSnapshot: (string) $line->conversion_factor_snapshot,
                baseQuantityDelta: $baseQuantity,
                lotOnHandBaseQuantityDelta: $line->inventory_lot_id === null
                    ? null
                    : $baseQuantity,
                serializedTargetStatus: $hasSerial ? SerializedInventoryUnitStatus::Available : null,
                serializedWarehouseSpecified: $hasSerial,
                serializedTargetWarehouseId: $hasSerial ? $targetWarehouseId : null,
                serializedTargetCustodyType: $hasSerial ? SerializedCustodyType::Warehouse : null,
                serializedTargetCustodyReferenceType: $hasSerial ? 'warehouse' : null,
                serializedTargetCustodyReferenceId: $hasSerial ? $targetWarehouseId : null,
                stockCondition: StockCondition::Saleable,
                serializedTargetStockCondition: $hasSerial ? StockCondition::Saleable : null,
                serializedInventoryLotSpecified: $hasSerial,
                serializedTargetInventoryLotId: $hasSerial ? $line->inventory_lot_id : null,
            );
        }

        return $commands;
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
                'The original movement has no complete UOM snapshot and cannot be corrected automatically.',
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

        if (bccomp($value, '0', self::QUANTITY_SCALE) !== 1) {
            throw new DomainException('The original movement does not contain a positive base quantity.');
        }

        return bcadd($value, '0', self::QUANTITY_SCALE);
    }

    /**
     * The delivery-side counterpart of {@see self::movementPositiveBaseQuantity()}: a delivery's
     * canonical Sale movement is posted with a negative base quantity (the source warehouse's
     * custody decreasing), so a delivery correction needs the magnitude being reversed rather
     * than the signed delta itself.
     *
     * @return numeric-string
     */
    private function movementAbsoluteBaseQuantity(InventoryMovement $movement): string
    {
        $value = $movement->base_quantity_delta ?? $movement->quantity;

        if (bccomp($value, '0', self::QUANTITY_SCALE) !== -1) {
            throw new DomainException('The original delivery movement does not contain a negative base quantity.');
        }

        return bcsub('0', $value, self::QUANTITY_SCALE);
    }

    /** @return numeric-string */
    private function postedCorrectionBaseQuantity(InventoryMovement $movement): string
    {
        return $this->decimal((string) InventoryCorrectionLine::query()
            ->where('original_inventory_movement_id', $movement->getKey())
            ->whereHas(
                'correction',
                fn (Builder $query): Builder => $query->where('status', InventoryCorrectionStatus::Posted->value),
            )
            ->sum('posted_base_quantity'));
    }

    /**
     * How much of a delivery's canonical Sale movement has already been posted back through a
     * *customer* return (WP-1.3) — the other half of the "prior corrections **and** prior
     * returns" cap a delivery correction must respect.
     *
     * @return numeric-string
     */
    private function postedReturnBaseQuantity(InventoryMovement $movement): string
    {
        return $this->decimal((string) InventoryReturnLine::query()
            ->where('original_inventory_movement_id', $movement->getKey())
            ->whereHas(
                'inventoryReturn',
                fn (Builder $query): Builder => $query
                    ->where('return_type', InventoryReturnType::Customer->value)
                    ->where('status', InventoryReturnStatus::Posted->value),
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
