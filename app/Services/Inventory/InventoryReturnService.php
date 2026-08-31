<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryPostingCommand;
use App\Enums\InventoryPostingBalanceMode;
use App\Enums\InventoryReturnDisposition;
use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
use App\Enums\MovementType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class InventoryReturnService
{
    private const QUANTITY_SCALE = 6;

    public function __construct(
        private InventoryPostingService $inventoryPostingService,
        private InventoryLotService $inventoryLotService,
        private QuantityNormalizer $quantityNormalizer,
        private InventoryAlertService $inventoryAlertService,
    ) {}

    public function createCustomerReturn(
        User $actor,
        InventoryOperation $delivery,
        Warehouse $warehouse,
        ?string $reason = null,
        ?string $notes = null,
    ): InventoryReturn {
        return DB::transaction(function () use ($actor, $delivery, $warehouse, $reason, $notes): InventoryReturn {
            $lockedDelivery = InventoryOperation::query()
                ->lockForUpdate()
                ->findOrFail($delivery->getKey());

            if (
                $lockedDelivery->operation_type !== OperationType::Delivery
                || $lockedDelivery->stage !== OperationStage::Done
                || ! is_int($lockedDelivery->customer_id)
            ) {
                throw new DomainException('Customer returns require a completed customer delivery.');
            }

            $lockedWarehouse = $this->usableWarehouse((int) $warehouse->getKey());

            return InventoryReturn::query()->forceCreate([
                'return_number' => $this->nextReturnNumber(),
                'return_type' => InventoryReturnType::Customer,
                'status' => InventoryReturnStatus::Draft,
                'warehouse_id' => $lockedWarehouse->getKey(),
                'customer_id' => $lockedDelivery->customer_id,
                'supplier_id' => null,
                'original_inventory_operation_id' => $lockedDelivery->getKey(),
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
        }, attempts: 5);
    }

    public function createSupplierReturn(
        User $actor,
        Supplier $supplier,
        Warehouse $warehouse,
        ?InventoryOperation $receipt = null,
        ?int $purchaseOrderId = null,
        ?string $reason = null,
        ?string $notes = null,
    ): InventoryReturn {
        return DB::transaction(function () use (
            $actor,
            $supplier,
            $warehouse,
            $receipt,
            $purchaseOrderId,
            $reason,
            $notes,
        ): InventoryReturn {
            $lockedSupplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->getKey());
            $lockedWarehouse = $this->usableWarehouse((int) $warehouse->getKey());

            $receiptId = null;

            if ($receipt instanceof InventoryOperation) {
                $lockedReceipt = InventoryOperation::query()->lockForUpdate()->findOrFail($receipt->getKey());

                if (
                    $lockedReceipt->operation_type !== OperationType::Receipt
                    || $lockedReceipt->stage !== OperationStage::Done
                    || $lockedReceipt->supplier_id !== $lockedSupplier->getKey()
                ) {
                    throw new DomainException('A supplier return receipt reference must be a completed receipt for the selected supplier.');
                }

                $receiptId = $lockedReceipt->getKey();
            }

            if ($purchaseOrderId !== null && $purchaseOrderId <= 0) {
                throw new DomainException('An optional purchase-order reference must be a positive identifier.');
            }

            return InventoryReturn::query()->forceCreate([
                'return_number' => $this->nextReturnNumber(),
                'return_type' => InventoryReturnType::Supplier,
                'status' => InventoryReturnStatus::Draft,
                'warehouse_id' => $lockedWarehouse->getKey(),
                'customer_id' => null,
                'supplier_id' => $lockedSupplier->getKey(),
                'original_inventory_operation_id' => $receiptId,
                'original_purchase_order_id' => $purchaseOrderId,
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
        }, attempts: 5);
    }

    public function addCustomerLine(
        InventoryReturn $return,
        InventoryOperationLine $deliveryLine,
        string $transactionQuantity,
        ?int $inventoryLotId = null,
        ?int $serializedInventoryUnitId = null,
    ): InventoryReturnLine {
        return DB::transaction(function () use (
            $return,
            $deliveryLine,
            $transactionQuantity,
            $inventoryLotId,
            $serializedInventoryUnitId,
        ): InventoryReturnLine {
            $lockedReturn = $this->lockedDraft($return, InventoryReturnType::Customer);
            $line = InventoryOperationLine::query()
                ->with(['productVariant.product'])
                ->lockForUpdate()
                ->findOrFail($deliveryLine->getKey());

            if ($line->inventory_operation_id !== $lockedReturn->original_inventory_operation_id) {
                throw new DomainException('The selected return line does not belong to the original delivery.');
            }

            $snapshot = $this->customerReturnSnapshot($line, $transactionQuantity);
            $this->assertCustomerAllocation(
                $lockedReturn,
                $line,
                $snapshot['base_quantity'],
                $inventoryLotId,
                $serializedInventoryUnitId,
            );

            $alreadyDrafted = $lockedReturn->lines()
                ->where('original_inventory_operation_line_id', $line->getKey())
                ->sum('base_quantity');
            $posted = $this->postedCustomerReturnBaseQuantity($line);
            $originalBase = $this->positiveBaseQuantity((string) $line->base_quantity);
            $remaining = bcsub(
                bcsub($originalBase, $this->decimal((string) $posted), self::QUANTITY_SCALE),
                $this->decimal((string) $alreadyDrafted),
                self::QUANTITY_SCALE,
            );

            if (bccomp($snapshot['base_quantity'], $remaining, self::QUANTITY_SCALE) === 1) {
                throw new DomainException('The requested return quantity exceeds the quantity still returnable from the delivery line.');
            }

            $originalMovement = InventoryMovement::query()
                ->where('movement_type', MovementType::Sale->value)
                ->where('source_type', 'inventory_operation')
                ->where('source_id', $lockedReturn->original_inventory_operation_id)
                ->where('source_line_type', 'inventory_operation_line')
                ->where('source_line_id', $line->getKey())
                ->lockForUpdate()
                ->first();

            if (! $originalMovement instanceof InventoryMovement) {
                throw new DomainException('The original delivery movement cannot be resolved for this return line.');
            }

            return $lockedReturn->lines()->create([
                'product_variant_id' => $line->product_variant_id,
                'transaction_quantity' => $snapshot['transaction_quantity'],
                'transaction_unit_id' => $snapshot['transaction_unit_id'],
                'conversion_factor_snapshot' => $snapshot['conversion_factor_snapshot'],
                'base_quantity' => $snapshot['base_quantity'],
                'inventory_lot_id' => $inventoryLotId,
                'serialized_inventory_unit_id' => $serializedInventoryUnitId,
                'original_inventory_operation_line_id' => $line->getKey(),
                'original_inventory_movement_id' => $originalMovement->getKey(),
            ]);
        }, attempts: 5);
    }

    public function addSupplierLine(
        InventoryReturn $return,
        ProductVariant $variant,
        int $transactionUnitId,
        string $transactionQuantity,
        StockCondition $sourceCondition,
        ?int $inventoryLotId = null,
        ?int $serializedInventoryUnitId = null,
        ?InventoryOperationLine $receiptLine = null,
    ): InventoryReturnLine {
        return DB::transaction(function () use (
            $return,
            $variant,
            $transactionUnitId,
            $transactionQuantity,
            $sourceCondition,
            $inventoryLotId,
            $serializedInventoryUnitId,
            $receiptLine,
        ): InventoryReturnLine {
            $lockedReturn = $this->lockedDraft($return, InventoryReturnType::Supplier);

            if (! $sourceCondition->isMaterialized()) {
                throw new DomainException('Supplier returns require a materialized warehouse source condition.');
            }

            $lockedVariant = ProductVariant::query()
                ->with('product')
                ->lockForUpdate()
                ->findOrFail($variant->getKey());

            $snapshot = $this->quantityNormalizer->normalize(
                $lockedVariant,
                $transactionUnitId,
                $transactionQuantity,
            );

            $originalLineId = null;
            $originalMovementId = null;

            if ($receiptLine instanceof InventoryOperationLine) {
                $lockedReceiptLine = InventoryOperationLine::query()
                    ->with('operation')
                    ->lockForUpdate()
                    ->findOrFail($receiptLine->getKey());

                if (
                    $lockedReturn->original_inventory_operation_id !== $lockedReceiptLine->inventory_operation_id
                    || $lockedReceiptLine->product_variant_id !== $lockedVariant->getKey()
                    || $lockedReceiptLine->operation?->operation_type !== OperationType::Receipt
                    || $lockedReceiptLine->operation?->stage !== OperationStage::Done
                ) {
                    throw new DomainException('The selected supplier-return receipt line is not valid provenance for this return.');
                }

                if (
                    $inventoryLotId !== null
                    && $lockedReceiptLine->inventory_lot_id !== null
                    && $inventoryLotId !== $lockedReceiptLine->inventory_lot_id
                ) {
                    throw new DomainException('The supplier-return lot does not match the referenced receipt line.');
                }

                $originalBase = $this->positiveBaseQuantity((string) $lockedReceiptLine->base_quantity);
                $alreadyDrafted = $lockedReturn->lines()
                    ->where('original_inventory_operation_line_id', $lockedReceiptLine->getKey())
                    ->sum('base_quantity');
                $alreadyPosted = $this->postedSupplierReturnBaseQuantity($lockedReceiptLine);
                $remaining = bcsub(
                    bcsub($originalBase, $alreadyPosted, self::QUANTITY_SCALE),
                    $this->decimal((string) $alreadyDrafted),
                    self::QUANTITY_SCALE,
                );

                if (bccomp($snapshot->baseQuantity, $remaining, self::QUANTITY_SCALE) === 1) {
                    throw new DomainException(
                        'The requested supplier return quantity exceeds the quantity still returnable from the referenced receipt line.',
                    );
                }

                $originalLineId = $lockedReceiptLine->getKey();
                $originalMovementId = InventoryMovement::query()
                    ->where('movement_type', MovementType::Receipt->value)
                    ->where('source_type', 'inventory_operation')
                    ->where('source_id', $lockedReceiptLine->inventory_operation_id)
                    ->where('source_line_type', 'inventory_operation_line')
                    ->where('source_line_id', $lockedReceiptLine->getKey())
                    ->value('id');
            }

            $this->assertSupplierAllocation(
                $lockedReturn,
                $lockedVariant,
                $snapshot->baseQuantity,
                $sourceCondition,
                $inventoryLotId,
                $serializedInventoryUnitId,
            );

            return $lockedReturn->lines()->create([
                'product_variant_id' => $lockedVariant->getKey(),
                'transaction_quantity' => $snapshot->transactionQuantity,
                'transaction_unit_id' => $snapshot->transactionUnitId,
                'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
                'base_quantity' => $snapshot->baseQuantity,
                'source_condition' => $sourceCondition,
                'inventory_lot_id' => $inventoryLotId,
                'serialized_inventory_unit_id' => $serializedInventoryUnitId,
                'original_inventory_operation_line_id' => $originalLineId,
                'original_inventory_movement_id' => $originalMovementId,
            ]);
        }, attempts: 5);
    }

    public function inspectLine(
        InventoryReturnLine $line,
        InventoryReturnDisposition $disposition,
        User $actor,
        ?string $notes = null,
    ): InventoryReturnLine {
        return DB::transaction(function () use ($line, $disposition, $actor, $notes): InventoryReturnLine {
            $lockedLine = InventoryReturnLine::query()
                ->with('inventoryReturn')
                ->lockForUpdate()
                ->findOrFail($line->getKey());
            $return = $lockedLine->inventoryReturn;

            if (
                ! $return instanceof InventoryReturn
                || $return->return_type !== InventoryReturnType::Customer
                || $return->status !== InventoryReturnStatus::Draft
            ) {
                throw new DomainException('Only a draft customer return line can be inspected.');
            }

            $lockedLine->forceFill([
                'disposition' => $disposition,
                'inspection_notes' => $notes,
                'inspected_by' => $actor->getKey(),
                'inspected_at' => now(),
            ])->save();

            return $lockedLine->refresh();
        }, attempts: 5);
    }

    public function markReady(InventoryReturn $return, User $actor): InventoryReturn
    {
        return DB::transaction(function () use ($return, $actor): InventoryReturn {
            $locked = InventoryReturn::query()->lockForUpdate()->findOrFail($return->getKey());

            if (! $locked->isDraft()) {
                throw new DomainException('Only a draft return can be marked ready.');
            }

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();

            if ($lines->isEmpty()) {
                throw new DomainException('A return must contain at least one line.');
            }

            if ($locked->return_type === InventoryReturnType::Customer) {
                foreach ($lines as $line) {
                    if ($line->disposition === null || $line->inspected_at === null) {
                        throw new DomainException('Every customer return line must be inspected and have a disposition before posting.');
                    }
                }
            } else {
                foreach ($lines as $line) {
                    if (! $line->source_condition instanceof StockCondition) {
                        throw new DomainException('Every supplier return line requires a source stock condition.');
                    }
                }
            }

            $locked->forceFill([
                'status' => InventoryReturnStatus::Ready,
                'ready_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            return $locked->refresh();
        }, attempts: 5);
    }

    public function post(InventoryReturn $return, User $actor): InventoryReturn
    {
        return DB::transaction(function () use ($return, $actor): InventoryReturn {
            $locked = InventoryReturn::query()->lockForUpdate()->findOrFail($return->getKey());

            if (! $locked->isReady()) {
                throw new DomainException('Only a ready return can be posted.');
            }

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();

            if ($lines->isEmpty()) {
                throw new DomainException('A return must contain at least one line.');
            }

            $commands = $locked->return_type === InventoryReturnType::Customer
                ? $this->customerPostingCommands($locked, $lines, $actor)
                : $this->supplierPostingCommands($locked, $lines, $actor);

            $results = $this->inventoryPostingService->postMany($commands);

            foreach ($lines->values() as $index => $line) {
                $posting = $results[$index];

                $line->forceFill([
                    'posted_base_quantity' => $line->base_quantity,
                    'posted_inventory_movement_id' => $posting->movement->getKey(),
                ])->save();

                $this->inventoryAlertService->syncStock($posting->stock);
            }

            $locked->forceFill([
                'status' => InventoryReturnStatus::Posted,
                'posted_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'return_type' => $locked->return_type->value,
                    'line_count' => $lines->count(),
                ])
                ->log('inventory.return.posted');

            return $locked->refresh();
        }, attempts: 5);
    }

    public function removeLine(InventoryReturnLine $line): void
    {
        DB::transaction(function () use ($line): void {
            $lockedLine = InventoryReturnLine::query()
                ->with('inventoryReturn')
                ->lockForUpdate()
                ->findOrFail($line->getKey());

            $return = $lockedLine->inventoryReturn;

            if (! $return instanceof InventoryReturn || ! $return->isDraft()) {
                throw new DomainException('Return lines can only be removed while the return is a draft.');
            }

            $lockedLine->delete();
        }, attempts: 5);
    }

    public function cancel(InventoryReturn $return, User $actor, ?string $reason = null): InventoryReturn
    {
        return DB::transaction(function () use ($return, $actor, $reason): InventoryReturn {
            $locked = InventoryReturn::query()->lockForUpdate()->findOrFail($return->getKey());

            if ($locked->isTerminal()) {
                throw new DomainException('A posted or cancelled return cannot be cancelled.');
            }

            $locked->forceFill([
                'status' => InventoryReturnStatus::Cancelled,
                'cancelled_at' => now(),
                'notes' => $reason ?? $locked->notes,
                'updated_by' => $actor->getKey(),
            ])->save();

            return $locked->refresh();
        }, attempts: 5);
    }

    /**
     * @param Collection<int, InventoryReturnLine> $lines
     * @return list<InventoryPostingCommand>
     */
    private function customerPostingCommands(
        InventoryReturn $return,
        Collection $lines,
        User $actor,
    ): array {
        $commands = [];

        foreach ($lines as $line) {
            $originalLine = InventoryOperationLine::query()
                ->with(['operation', 'productVariant.product'])
                ->lockForUpdate()
                ->findOrFail($line->original_inventory_operation_line_id);

            if (
                $originalLine->operation?->operation_type !== OperationType::Delivery
                || $originalLine->operation?->stage !== OperationStage::Done
                || $originalLine->inventory_operation_id !== $return->original_inventory_operation_id
                || $originalLine->product_variant_id !== $line->product_variant_id
            ) {
                throw new DomainException('The original delivery evidence is no longer valid for this customer return.');
            }

            $alreadyPosted = $this->postedCustomerReturnBaseQuantity($originalLine, (int) $return->getKey());
            $returnable = bcsub(
                $this->positiveBaseQuantity((string) $originalLine->base_quantity),
                $alreadyPosted,
                self::QUANTITY_SCALE,
            );
            $currentReturnTotal = $this->decimal((string) $lines
                ->where('original_inventory_operation_line_id', $originalLine->getKey())
                ->sum('base_quantity'));

            if (bccomp($currentReturnTotal, $returnable, self::QUANTITY_SCALE) === 1) {
                throw new DomainException('The customer return would exceed the remaining delivered quantity.');
            }

            $disposition = $line->disposition;

            if (! $disposition instanceof InventoryReturnDisposition || $line->inspected_at === null) {
                throw new DomainException('Customer return lines require an inspected disposition.');
            }

            $condition = $disposition->stockCondition();
            $variant = $originalLine->productVariant;

            if (! $variant instanceof ProductVariant) {
                throw new DomainException('The original delivery variant no longer exists.');
            }

            $this->assertCustomerAllocation(
                $return,
                $originalLine,
                (string) $line->base_quantity,
                $line->inventory_lot_id,
                $line->serialized_inventory_unit_id,
            );

            $serialStatus = $condition === StockCondition::Damaged
                ? SerializedInventoryUnitStatus::Damaged
                : SerializedInventoryUnitStatus::Available;

            $commands[] = new InventoryPostingCommand(
                productVariantId: $line->product_variant_id,
                warehouseId: $return->warehouse_id,
                onHandBaseQuantityDelta: (string) $line->base_quantity,
                reservedBaseQuantityDelta: '0.000000',
                damagedBaseQuantityDelta: $condition === StockCondition::Damaged
                    ? (string) $line->base_quantity
                    : '0.000000',
                movementType: MovementType::Return,
                movementBaseQuantityDelta: (string) $line->base_quantity,
                sourceType: 'inventory_return',
                sourceId: (int) $return->getKey(),
                actorId: (int) $actor->getKey(),
                notes: $line->inspection_notes ?? $return->notes,
                serializedInventoryUnitId: $line->serialized_inventory_unit_id,
                idempotencyKey: sprintf(
                    'inventory-return:%d:line:%d:post',
                    $return->getKey(),
                    $line->getKey(),
                ),
                balanceMode: InventoryPostingBalanceMode::CreateIfMissing,
                inventoryLotId: $line->inventory_lot_id,
                sourceLineType: 'inventory_return_line',
                sourceLineId: (int) $line->getKey(),
                transactionQuantity: (string) $line->transaction_quantity,
                transactionUnitId: $line->transaction_unit_id,
                conversionFactorSnapshot: (string) $line->conversion_factor_snapshot,
                baseQuantityDelta: (string) $line->base_quantity,
                lotOnHandBaseQuantityDelta: $line->inventory_lot_id === null
                    ? null
                    : (string) $line->base_quantity,
                serializedTargetStatus: $line->serialized_inventory_unit_id === null ? null : $serialStatus,
                serializedWarehouseSpecified: $line->serialized_inventory_unit_id !== null,
                serializedTargetWarehouseId: $line->serialized_inventory_unit_id === null
                    ? null
                    : $return->warehouse_id,
                serializedTargetCustodyType: $line->serialized_inventory_unit_id === null
                    ? null
                    : SerializedCustodyType::Warehouse,
                serializedTargetCustodyReferenceType: $line->serialized_inventory_unit_id === null
                    ? null
                    : 'warehouse',
                serializedTargetCustodyReferenceId: $line->serialized_inventory_unit_id === null
                    ? null
                    : $return->warehouse_id,
                stockCondition: $condition,
                serializedTargetStockCondition: $line->serialized_inventory_unit_id === null
                    ? null
                    : $condition,
                serializedInventoryLotSpecified: $line->serialized_inventory_unit_id !== null,
                serializedTargetInventoryLotId: $line->serialized_inventory_unit_id === null
                    ? null
                    : $line->inventory_lot_id,
            );
        }

        return $commands;
    }

    /**
     * @param Collection<int, InventoryReturnLine> $lines
     * @return list<InventoryPostingCommand>
     */
    private function supplierPostingCommands(
        InventoryReturn $return,
        Collection $lines,
        User $actor,
    ): array {
        if (! is_int($return->supplier_id)) {
            throw new DomainException('A supplier return requires a supplier.');
        }

        $commands = [];

        foreach ($lines as $line) {
            $variant = ProductVariant::query()
                ->with('product')
                ->lockForUpdate()
                ->findOrFail($line->product_variant_id);
            $condition = $line->source_condition;

            if ($line->original_inventory_operation_line_id !== null) {
                $receiptLine = InventoryOperationLine::query()
                    ->with('operation')
                    ->lockForUpdate()
                    ->findOrFail($line->original_inventory_operation_line_id);

                if (
                    $receiptLine->operation?->operation_type !== OperationType::Receipt
                    || $receiptLine->operation?->stage !== OperationStage::Done
                    || $receiptLine->inventory_operation_id !== $return->original_inventory_operation_id
                    || $receiptLine->product_variant_id !== $line->product_variant_id
                ) {
                    throw new DomainException('The supplier return receipt provenance is no longer valid.');
                }

                $alreadyPosted = $this->postedSupplierReturnBaseQuantity(
                    $receiptLine,
                    (int) $return->getKey(),
                );
                $returnable = bcsub(
                    $this->positiveBaseQuantity((string) $receiptLine->base_quantity),
                    $alreadyPosted,
                    self::QUANTITY_SCALE,
                );
                $currentReturnTotal = $this->decimal((string) $lines
                    ->where('original_inventory_operation_line_id', $receiptLine->getKey())
                    ->sum('base_quantity'));

                if (bccomp($currentReturnTotal, $returnable, self::QUANTITY_SCALE) === 1) {
                    throw new DomainException(
                        'The supplier return would exceed the remaining quantity from the referenced receipt line.',
                    );
                }
            }

            if (! $condition instanceof StockCondition || ! $condition->isMaterialized()) {
                throw new DomainException('Supplier return lines require a materialized source condition.');
            }

            $this->assertSupplierAllocation(
                $return,
                $variant,
                (string) $line->base_quantity,
                $condition,
                $line->inventory_lot_id,
                $line->serialized_inventory_unit_id,
            );

            $negativeQuantity = bcsub('0', (string) $line->base_quantity, self::QUANTITY_SCALE);

            $commands[] = new InventoryPostingCommand(
                productVariantId: $line->product_variant_id,
                warehouseId: $return->warehouse_id,
                onHandBaseQuantityDelta: $negativeQuantity,
                reservedBaseQuantityDelta: '0.000000',
                damagedBaseQuantityDelta: $condition === StockCondition::Damaged
                    ? $negativeQuantity
                    : '0.000000',
                movementType: MovementType::Return,
                movementBaseQuantityDelta: $negativeQuantity,
                sourceType: 'inventory_return',
                sourceId: (int) $return->getKey(),
                actorId: (int) $actor->getKey(),
                notes: $return->notes,
                serializedInventoryUnitId: $line->serialized_inventory_unit_id,
                idempotencyKey: sprintf(
                    'inventory-return:%d:line:%d:post',
                    $return->getKey(),
                    $line->getKey(),
                ),
                balanceMode: InventoryPostingBalanceMode::RequireExisting,
                inventoryLotId: $line->inventory_lot_id,
                sourceLineType: 'inventory_return_line',
                sourceLineId: (int) $line->getKey(),
                transactionQuantity: (string) $line->transaction_quantity,
                transactionUnitId: $line->transaction_unit_id,
                conversionFactorSnapshot: (string) $line->conversion_factor_snapshot,
                baseQuantityDelta: $negativeQuantity,
                lotOnHandBaseQuantityDelta: $line->inventory_lot_id === null
                    ? null
                    : $negativeQuantity,
                serializedTargetStatus: $line->serialized_inventory_unit_id === null
                    ? null
                    : SerializedInventoryUnitStatus::ReturnedToSupplier,
                serializedWarehouseSpecified: $line->serialized_inventory_unit_id !== null,
                serializedTargetWarehouseId: null,
                serializedTargetCustodyType: $line->serialized_inventory_unit_id === null
                    ? null
                    : SerializedCustodyType::Supplier,
                serializedTargetCustodyReferenceType: $line->serialized_inventory_unit_id === null
                    ? null
                    : 'supplier',
                serializedTargetCustodyReferenceId: $line->serialized_inventory_unit_id === null
                    ? null
                    : $return->supplier_id,
                stockCondition: $condition,
                serializedTargetStockCondition: $line->serialized_inventory_unit_id === null
                    ? null
                    : $condition,
            );
        }

        return $commands;
    }

    private function assertCustomerAllocation(
        InventoryReturn $return,
        InventoryOperationLine $deliveryLine,
        string $baseQuantity,
        ?int $inventoryLotId,
        ?int $serializedInventoryUnitId,
    ): void {
        $variant = $deliveryLine->productVariant;

        if (! $variant instanceof ProductVariant) {
            throw new DomainException('The original delivery variant no longer exists.');
        }

        $tracksBatches = $variant->productType()?->tracksBatches() === true;
        $tracksSerials = $variant->productType()?->tracksSerials() === true;

        if ($tracksBatches && ! is_int($inventoryLotId)) {
            throw new DomainException('A lot allocation is required for this customer return.');
        }

        if (! $tracksBatches && $inventoryLotId !== null) {
            throw new DomainException('A lot allocation is not valid for this customer return variant.');
        }

        if ($tracksBatches && $deliveryLine->inventory_lot_id !== $inventoryLotId) {
            throw new DomainException('The customer return lot must match the lot originally delivered.');
        }

        if ($tracksSerials) {
            if (! is_int($serializedInventoryUnitId) || bccomp($baseQuantity, '1.000000', self::QUANTITY_SCALE) !== 0) {
                throw new DomainException('A serialized customer return must reference exactly one delivered serial.');
            }

            if ($deliveryLine->serialized_inventory_unit_id !== $serializedInventoryUnitId) {
                throw new DomainException('The customer return serial must match the serial originally delivered.');
            }

            $unit = SerializedInventoryUnit::query()->lockForUpdate()->find($serializedInventoryUnitId);

            if (
                ! $unit instanceof SerializedInventoryUnit
                || $unit->product_variant_id !== $deliveryLine->product_variant_id
                || $unit->status !== SerializedInventoryUnitStatus::Delivered
                || $unit->custody_type !== SerializedCustodyType::Customer
                || (
                    is_int($return->customer_id)
                    && $unit->custody_reference_type === 'customer'
                    && $unit->custody_reference_id !== $return->customer_id
                )
                || (
                    $inventoryLotId !== null
                    && $unit->inventory_lot_id !== $inventoryLotId
                )
            ) {
                throw new DomainException('The serialized unit is not currently held by the customer from this delivery allocation.');
            }

            $duplicate = InventoryReturnLine::query()
                ->where('serialized_inventory_unit_id', $serializedInventoryUnitId)
                ->where('inventory_return_id', '!=', $return->getKey())
                ->whereHas(
                    'inventoryReturn',
                    fn ($query) => $query
                        ->where('return_type', InventoryReturnType::Customer->value)
                        ->where('status', InventoryReturnStatus::Posted->value),
                )
                ->exists();

            if ($duplicate) {
                throw new DomainException('This serialized unit has already been returned.');
            }
        } elseif ($serializedInventoryUnitId !== null) {
            throw new DomainException('A serial allocation is not valid for this customer return variant.');
        }
    }

    private function assertSupplierAllocation(
        InventoryReturn $return,
        ProductVariant $variant,
        string $baseQuantity,
        StockCondition $sourceCondition,
        ?int $inventoryLotId,
        ?int $serializedInventoryUnitId,
    ): void {
        $tracksBatches = $variant->productType()?->tracksBatches() === true;
        $tracksSerials = $variant->productType()?->tracksSerials() === true;

        if ($tracksBatches) {
            if (! is_int($inventoryLotId)) {
                throw new DomainException('A supplier return for a lot-tracked item requires an explicit lot.');
            }

            $lot = InventoryLot::query()->canonical()->lockForUpdate()->find($inventoryLotId);

            if (
                ! $lot instanceof InventoryLot
                || $lot->product_variant_id !== $variant->getKey()
            ) {
                throw new DomainException('The supplier-return lot does not belong to this variant.');
            }

            $balance = $this->inventoryLotService->conditionBalanceForUpdate(
                $lot,
                $return->warehouse_id,
                $sourceCondition,
            );

            if (
                $balance === null
                || bccomp((string) $balance->on_hand_base_quantity, $baseQuantity, self::QUANTITY_SCALE) === -1
                || (
                    $sourceCondition === StockCondition::Saleable
                    && bccomp(
                        bcsub(
                            (string) $balance->on_hand_base_quantity,
                            (string) $balance->reserved_base_quantity,
                            self::QUANTITY_SCALE,
                        ),
                        $baseQuantity,
                        self::QUANTITY_SCALE,
                    ) === -1
                )
            ) {
                throw new DomainException('The selected lot does not have enough eligible quantity for the supplier return.');
            }
        } elseif ($inventoryLotId !== null) {
            throw new DomainException('A supplier-return lot is not valid for this variant.');
        }

        if ($tracksSerials) {
            if (! is_int($serializedInventoryUnitId) || bccomp($baseQuantity, '1.000000', self::QUANTITY_SCALE) !== 0) {
                throw new DomainException('A serialized supplier return must reference exactly one serial.');
            }

            $unit = SerializedInventoryUnit::query()->lockForUpdate()->find($serializedInventoryUnitId);
            $expectedStatus = $sourceCondition === StockCondition::Damaged
                ? SerializedInventoryUnitStatus::Damaged
                : SerializedInventoryUnitStatus::Available;

            if (
                ! $unit instanceof SerializedInventoryUnit
                || $unit->product_variant_id !== $variant->getKey()
                || $unit->warehouse_id !== $return->warehouse_id
                || $unit->custody_type !== SerializedCustodyType::Warehouse
                || $unit->stock_condition !== $sourceCondition
                || $unit->status !== $expectedStatus
                || (
                    $inventoryLotId !== null
                    && $unit->inventory_lot_id !== $inventoryLotId
                )
            ) {
                throw new DomainException('The serialized unit is not eligible for this supplier return.');
            }
        } elseif ($serializedInventoryUnitId !== null) {
            throw new DomainException('A supplier-return serial is not valid for this variant.');
        }
    }

    private function lockedDraft(
        InventoryReturn $return,
        InventoryReturnType $type,
    ): InventoryReturn {
        $locked = InventoryReturn::query()->lockForUpdate()->findOrFail($return->getKey());

        if (! $locked->isDraft() || $locked->return_type !== $type) {
            throw new DomainException('Return lines can only be changed on a draft return of the matching type.');
        }

        return $locked;
    }

    /**
     * @return array{
     *   transaction_quantity:numeric-string,
     *   transaction_unit_id:int,
     *   conversion_factor_snapshot:numeric-string,
     *   base_quantity:numeric-string
     * }
     */
    private function customerReturnSnapshot(
        InventoryOperationLine $deliveryLine,
        string $transactionQuantity,
    ): array {
        $factor = $deliveryLine->conversion_factor_snapshot;
        $transactionUnitId = $deliveryLine->transaction_unit_id;

        if (! is_string($factor) || ! is_int($transactionUnitId) || bccomp($factor, '0', 6) !== 1) {
            throw new DomainException('The original delivery line does not contain a complete UOM snapshot.');
        }

        $quantity = $this->positiveDecimal($transactionQuantity);
        $baseQuantity = bcmul($quantity, $factor, 12);

        return [
            'transaction_quantity' => bcadd($quantity, '0', 6),
            'transaction_unit_id' => $transactionUnitId,
            'conversion_factor_snapshot' => bcadd($factor, '0', 6),
            'base_quantity' => bcadd($baseQuantity, '0', 6),
        ];
    }

    /** @return numeric-string */
    private function postedCustomerReturnBaseQuantity(
        InventoryOperationLine $deliveryLine,
        ?int $excludingReturnId = null,
    ): string {
        $query = InventoryReturnLine::query()
            ->where('original_inventory_operation_line_id', $deliveryLine->getKey())
            ->whereHas(
                'inventoryReturn',
                fn ($returnQuery) => $returnQuery
                    ->where('return_type', InventoryReturnType::Customer->value)
                    ->where('status', InventoryReturnStatus::Posted->value),
            );

        if ($excludingReturnId !== null) {
            $query->where('inventory_return_id', '!=', $excludingReturnId);
        }

        return $this->decimal((string) $query->sum('posted_base_quantity'));
    }

    /** @return numeric-string */
    private function postedSupplierReturnBaseQuantity(
        InventoryOperationLine $receiptLine,
        ?int $excludingReturnId = null,
    ): string {
        $query = InventoryReturnLine::query()
            ->where('original_inventory_operation_line_id', $receiptLine->getKey())
            ->whereHas(
                'inventoryReturn',
                fn ($returnQuery) => $returnQuery
                    ->where('return_type', InventoryReturnType::Supplier->value)
                    ->where('status', InventoryReturnStatus::Posted->value),
            );

        if ($excludingReturnId !== null) {
            $query->where('inventory_return_id', '!=', $excludingReturnId);
        }

        return $this->decimal((string) $query->sum('posted_base_quantity'));
    }

    private function usableWarehouse(int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);

        if (! $warehouse->is_active) {
            throw new DomainException('Inventory returns require an active warehouse.');
        }

        return $warehouse;
    }

    /** @return numeric-string */
    private function positiveBaseQuantity(string $quantity): string
    {
        $normalized = $this->positiveDecimal($quantity);

        return bcadd($normalized, '0', self::QUANTITY_SCALE);
    }

    /** @return numeric-string */
    private function positiveDecimal(string $value): string
    {
        if (
            ! is_numeric($value)
            || preg_match('/^\d+(?:\.\d{1,6})?$/D', $value) !== 1
            || bccomp($value, '0', self::QUANTITY_SCALE) !== 1
        ) {
            throw new DomainException('Return quantities must be positive exact decimals with at most six decimal places.');
        }

        return $value;
    }

    /** @return numeric-string */
    private function decimal(string $value): string
    {
        if (! is_numeric($value)) {
            throw new DomainException('Return quantities must be numeric.');
        }

        return bcadd($value, '0', self::QUANTITY_SCALE);
    }

    private function nextReturnNumber(): string
    {
        $max = InventoryReturn::query()
            ->whereNotNull('return_number')
            ->lockForUpdate()
            ->max('return_number');

        return sprintf(
            'RET-%06d',
            is_string($max) ? ((int) mb_substr($max, 4)) + 1 : 1,
        );
    }
}
