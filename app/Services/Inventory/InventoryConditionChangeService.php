<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryPostingCommand;
use App\Data\Inventory\QuarantineDispositionData;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\InventoryPostingBalanceMode;
use App\Enums\MovementType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\QuarantineDisposition;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Exceptions\Domain\IllegalStatusTransition;
use App\Exceptions\Domain\QuarantineDispositionRejected;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryConditionChange;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturn;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Sales\DocumentNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

final readonly class InventoryConditionChangeService
{
    private const int QUANTITY_SCALE = 6;

    public function __construct(
        private InventoryPostingService $postingService,
        private InventoryReturnService $returnService,
        private InventoryAlertService $alertService,
        private DocumentNumberGenerator $numbers,
    ) {}

    public function draftQuarantineDisposition(
        QuarantineDispositionData $data,
        User $actor,
    ): InventoryConditionChange {
        Gate::forUser($actor)->authorize('create', InventoryConditionChange::class);

        $quantity = $this->positiveQuantity($data->baseQuantity);
        $reason = mb_trim($data->reason);

        if ($reason === '') {
            throw QuarantineDispositionRejected::because('a reason is required');
        }

        return DB::transaction(function () use ($data, $actor, $quantity, $reason): InventoryConditionChange {
            $variant = ProductVariant::query()
                ->with('product')
                ->lockForUpdate()
                ->findOrFail($data->productVariantId);
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($data->warehouseId);

            if (! $warehouse->is_active) {
                throw QuarantineDispositionRejected::because('the selected warehouse is inactive');
            }

            $this->validateTrackingIdentity(
                $variant,
                $warehouse,
                $quantity,
                $data->inventoryLotId,
                $data->serializedInventoryUnitId,
                requireQuarantineBalance: false,
            );

            $documentNumber = $this->numbers->next(
                InventoryConditionChange::withTrashed(),
                'document_number',
                'ICC-',
            );

            $inspectorId = $data->inspectedBy ?? $this->integerKey($actor, 'user');
            $inspectionTime = $data->inspectedAt ?? CarbonImmutable::now();

            return InventoryConditionChange::query()->forceCreate([
                'document_number' => $documentNumber,
                'type' => InventoryConditionChangeType::QuarantineDisposition,
                'status' => InventoryConditionChangeStatus::Draft,
                'product_variant_id' => $variant->getKey(),
                'warehouse_id' => $warehouse->getKey(),
                'inventory_lot_id' => $data->inventoryLotId,
                'serialized_inventory_unit_id' => $data->serializedInventoryUnitId,
                'condition_from' => StockCondition::Quarantine,
                'condition_to' => $data->disposition->conditionTo(),
                'base_quantity' => $quantity,
                'disposition' => $data->disposition,
                'reason_category' => $data->reasonCategory,
                'reason' => $reason,
                'inspected_by' => $inspectorId,
                'inspected_at' => $inspectionTime,
                'created_by' => $actor->getKey(),
            ]);
        }, attempts: 5);
    }

    public function post(InventoryConditionChange $change, User $actor): InventoryConditionChange
    {
        Gate::forUser($actor)->authorize('post', $change);

        return DB::transaction(function () use ($change, $actor): InventoryConditionChange {
            $locked = $this->lockChange($change);
            $this->assertTransition($locked, InventoryConditionChangeStatus::Posted);

            if (
                $locked->type !== InventoryConditionChangeType::QuarantineDisposition
                || $locked->condition_from !== StockCondition::Quarantine
                || ! $locked->disposition instanceof QuarantineDisposition
            ) {
                throw QuarantineDispositionRejected::because('the document is not a quarantine disposition');
            }

            $quantity = $this->positiveQuantity((string) $locked->base_quantity);
            $variant = ProductVariant::query()
                ->with('product')
                ->lockForUpdate()
                ->findOrFail($locked->product_variant_id);
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($locked->warehouse_id);

            if (! $warehouse->is_active) {
                throw QuarantineDispositionRejected::because('the selected warehouse is inactive');
            }

            [$lot, $unit] = $this->validateTrackingIdentity(
                $variant,
                $warehouse,
                $quantity,
                $locked->inventory_lot_id,
                $locked->serialized_inventory_unit_id,
                requireQuarantineBalance: true,
            );

            if ($locked->disposition->requiresSupplierReturn()) {
                $return = $this->returnToSupplier(
                    $locked,
                    $variant,
                    $warehouse,
                    $lot,
                    $unit,
                    $actor,
                    $quantity,
                );

                $locked->forceFill([
                    'supplier_return_id' => $return->getKey(),
                    'posted_by' => $actor->getKey(),
                    'posted_at' => now(),
                    'status' => InventoryConditionChangeStatus::Posted,
                ])->save();

                $this->auditPosted($locked, $actor, null);

                return $locked->refresh();
            }

            $posting = $this->postingService->post(
                $this->postingCommand($locked, $variant, $actor, $quantity, $unit),
            );

            $locked->forceFill([
                'inventory_movement_id' => $posting->movement->getKey(),
                'posted_by' => $actor->getKey(),
                'posted_at' => now(),
                'status' => InventoryConditionChangeStatus::Posted,
            ])->save();

            $this->auditPosted($locked, $actor, $posting->balanceBefore->toAuditValues());
            $this->alertService->syncStock($posting->stock);

            return $locked->refresh();
        }, attempts: 5);
    }

    public function cancel(
        InventoryConditionChange $change,
        User $actor,
        string $reason,
    ): InventoryConditionChange {
        Gate::forUser($actor)->authorize('cancel', $change);

        return DB::transaction(function () use ($change, $actor, $reason): InventoryConditionChange {
            $locked = $this->lockChange($change);
            $this->assertTransition($locked, InventoryConditionChangeStatus::Cancelled);

            $reason = mb_trim($reason);

            if ($reason === '') {
                throw QuarantineDispositionRejected::because('a cancellation reason is required');
            }

            $locked->forceFill([
                'status' => InventoryConditionChangeStatus::Cancelled,
                'reason' => $locked->reason."\nCancellation: ".$reason,
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['status' => InventoryConditionChangeStatus::Cancelled->value]])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'ip_address' => request()->ip(),
                    'reason' => $reason,
                ])
                ->log('inventory.condition_change.cancelled');

            return $locked->refresh();
        }, attempts: 5);
    }

    private function lockChange(InventoryConditionChange $change): InventoryConditionChange
    {
        $id = $this->integerKey($change, 'inventory condition change');

        return InventoryConditionChange::query()->lockForUpdate()->findOrFail($id);
    }

    private function assertTransition(
        InventoryConditionChange $change,
        InventoryConditionChangeStatus $target,
    ): void {
        if ($change->status->canTransitionTo($target)) {
            return;
        }

        throw IllegalStatusTransition::between(
            'Inventory condition change '.$change->document_number,
            $change->status->value,
            $target->value,
        );
    }

    /**
     * @return array{0:InventoryLot|null,1:SerializedInventoryUnit|null}
     */
    private function validateTrackingIdentity(
        ProductVariant $variant,
        Warehouse $warehouse,
        string $quantity,
        ?int $inventoryLotId,
        ?int $serializedUnitId,
        bool $requireQuarantineBalance,
    ): array {
        $tracksBatches = $variant->productType()?->tracksBatches() === true;
        $tracksSerials = $variant->productType()?->tracksSerials() === true;

        if ($tracksBatches && $inventoryLotId === null) {
            throw QuarantineDispositionRejected::because('a lot is required for this variant');
        }

        if ($tracksSerials && $serializedUnitId === null) {
            throw QuarantineDispositionRejected::because('a serialized unit is required for this variant');
        }

        if ($serializedUnitId !== null && $quantity !== '1.000000') {
            throw QuarantineDispositionRejected::because('serialized dispositions must move exactly one unit');
        }

        $lot = null;

        if ($inventoryLotId !== null) {
            $lot = InventoryLot::query()->lockForUpdate()->find($inventoryLotId);

            if (
                ! $lot instanceof InventoryLot
                || $lot->canonical_inventory_lot_id !== null
                || $lot->product_variant_id !== $variant->getKey()
            ) {
                throw QuarantineDispositionRejected::because('the selected lot does not match the variant');
            }

            if ($requireQuarantineBalance) {
                $lotBalance = InventoryLotBalance::query()
                    ->where('inventory_lot_id', $lot->getKey())
                    ->where('warehouse_id', $warehouse->getKey())
                    ->where('stock_condition', StockCondition::Quarantine->value)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $lotBalance instanceof InventoryLotBalance
                    || bccomp((string) $lotBalance->on_hand_base_quantity, $quantity, self::QUANTITY_SCALE) < 0
                ) {
                    throw QuarantineDispositionRejected::because('the lot does not contain enough quarantined quantity');
                }
            }
        }

        $unit = null;

        if ($serializedUnitId !== null) {
            $unit = SerializedInventoryUnit::query()->lockForUpdate()->find($serializedUnitId);

            if (
                ! $unit instanceof SerializedInventoryUnit
                || $unit->product_variant_id !== $variant->getKey()
                || $unit->warehouse_id !== $warehouse->getKey()
                || $unit->stock_condition !== StockCondition::Quarantine
                || $unit->status !== SerializedInventoryUnitStatus::Available
                || ($inventoryLotId !== null && $unit->inventory_lot_id !== $inventoryLotId)
            ) {
                throw QuarantineDispositionRejected::because('the serialized unit is not quarantined in the selected warehouse/lot');
            }
        }

        if ($requireQuarantineBalance) {
            $aggregate = InventoryConditionBalance::query()
                ->where('product_variant_id', $variant->getKey())
                ->where('warehouse_id', $warehouse->getKey())
                ->where('stock_condition', StockCondition::Quarantine->value)
                ->lockForUpdate()
                ->first();

            if (
                ! $aggregate instanceof InventoryConditionBalance
                || bccomp((string) $aggregate->on_hand_base_quantity, $quantity, self::QUANTITY_SCALE) < 0
            ) {
                throw QuarantineDispositionRejected::because('the warehouse does not contain enough quarantined quantity');
            }
        }

        return [$lot, $unit];
    }

    private function postingCommand(
        InventoryConditionChange $change,
        ProductVariant $variant,
        User $actor,
        string $quantity,
        ?SerializedInventoryUnit $unit,
    ): InventoryPostingCommand {
        $disposition = $change->disposition;

        if (! $disposition instanceof QuarantineDisposition || $disposition->requiresSupplierReturn()) {
            throw QuarantineDispositionRejected::because('the disposition must be posted through the supplier return workflow');
        }

        $target = $disposition->conditionTo();
        $movementType = match ($disposition) {
            QuarantineDisposition::ReleaseToSaleable => MovementType::DamageRecovery,
            QuarantineDisposition::DowngradeToDamaged => MovementType::Damage,
            QuarantineDisposition::Dispose => MovementType::Disposal,
            QuarantineDisposition::ReturnToSupplier => throw new LogicException('Supplier returns use InventoryReturnService.'),
        };
        $movementQuantity = $disposition === QuarantineDisposition::ReleaseToSaleable
            ? $quantity
            : bcsub('0', $quantity, self::QUANTITY_SCALE);
        $onHandDelta = $target->isMaterialized()
            ? '0.000000'
            : bcsub('0', $quantity, self::QUANTITY_SCALE);
        $damagedDelta = $target === StockCondition::Damaged
            ? $quantity
            : '0.000000';
        $changeId = $this->integerKey($change, 'inventory condition change');
        $actorId = $this->integerKey($actor, 'user');
        $unitId = $variant->unit_id;

        if (! is_int($unitId)) {
            throw new LogicException('Condition changes require a variant base-unit identifier.');
        }

        $serializedTargetStatus = $unit instanceof \App\Models\SerializedInventoryUnit ? match ($disposition) {
            QuarantineDisposition::ReleaseToSaleable => SerializedInventoryUnitStatus::Available,
            QuarantineDisposition::DowngradeToDamaged => SerializedInventoryUnitStatus::Damaged,
            QuarantineDisposition::Dispose => SerializedInventoryUnitStatus::Disposed,
            QuarantineDisposition::ReturnToSupplier => null,
        } : null;

        return new InventoryPostingCommand(
            productVariantId: (int) $change->product_variant_id,
            warehouseId: (int) $change->warehouse_id,
            onHandBaseQuantityDelta: $onHandDelta,
            reservedBaseQuantityDelta: '0.000000',
            damagedBaseQuantityDelta: $damagedDelta,
            movementType: $movementType,
            movementBaseQuantityDelta: $movementQuantity,
            sourceType: 'inventory_condition_change',
            sourceId: $changeId,
            actorId: $actorId,
            notes: $change->reason,
            serializedInventoryUnitId: $unit?->getKey(),
            idempotencyKey: sprintf('inventory-condition-change:%d:post', $changeId),
            balanceMode: InventoryPostingBalanceMode::RequireExisting,
            inventoryLotId: $change->inventory_lot_id,
            transactionQuantity: $quantity,
            transactionUnitId: $unitId,
            conversionFactorSnapshot: '1.000000',
            baseQuantityDelta: $movementQuantity,
            serializedTargetStatus: $serializedTargetStatus,
            serializedWarehouseSpecified: $disposition === QuarantineDisposition::Dispose && $unit instanceof \App\Models\SerializedInventoryUnit,
            serializedTargetCustodyType: $unit instanceof \App\Models\SerializedInventoryUnit ? ($disposition === QuarantineDisposition::Dispose ? SerializedCustodyType::Disposed : SerializedCustodyType::Warehouse) : (
                null
            ),
            serializedTargetCustodyReferenceType: $unit instanceof \App\Models\SerializedInventoryUnit ? ($disposition === QuarantineDisposition::Dispose ? 'inventory_condition_change' : 'warehouse') : (
                null
            ),
            serializedTargetCustodyReferenceId: $unit instanceof \App\Models\SerializedInventoryUnit ? ($disposition === QuarantineDisposition::Dispose ? $changeId : (int) $change->warehouse_id) : (
                null
            ),
            stockCondition: StockCondition::Quarantine,
            conditionFrom: StockCondition::Quarantine,
            conditionTo: $target,
            conditionTransferBaseQuantity: $quantity,
            serializedTargetStockCondition: $unit instanceof \App\Models\SerializedInventoryUnit ? $target : null,
        );
    }

    private function returnToSupplier(
        InventoryConditionChange $change,
        ProductVariant $variant,
        Warehouse $warehouse,
        ?InventoryLot $lot,
        ?SerializedInventoryUnit $unit,
        User $actor,
        string $quantity,
    ): InventoryReturn {
        [$supplier, $receipt, $receiptLine] = $this->resolveSupplierProvenance(
            $change,
            $lot,
            $unit,
        );

        $return = $this->returnService->createSupplierReturn(
            $actor,
            $supplier,
            $warehouse,
            $receipt,
            null,
            $change->reason,
            'Created from quarantine disposition '.$change->document_number,
        );

        $baseUnitId = $variant->unit_id;

        if (! is_int($baseUnitId)) {
            throw new LogicException('Supplier returns require a variant base-unit identifier.');
        }

        $this->returnService->addSupplierLine(
            $return,
            $variant,
            $baseUnitId,
            $quantity,
            StockCondition::Quarantine,
            $lot?->getKey(),
            $unit?->getKey(),
            $receiptLine,
        );

        $ready = $this->returnService->markReady($return, $actor);

        return $this->returnService->post($ready, $actor);
    }

    /**
     * @return array{0:Supplier,1:InventoryOperation,2:InventoryOperationLine|null}
     */
    private function resolveSupplierProvenance(
        InventoryConditionChange $change,
        ?InventoryLot $lot,
        ?SerializedInventoryUnit $unit,
    ): array {
        $movement = null;

        if ($unit instanceof SerializedInventoryUnit) {
            $movement = InventoryMovement::query()
                ->where('serialized_inventory_unit_id', $unit->getKey())
                ->where('movement_type', MovementType::Receipt->value)
                ->where('source_type', 'inventory_operation')
                ->oldest('id')
                ->lockForUpdate()
                ->first();
        }

        if (! $movement instanceof InventoryMovement && $lot instanceof InventoryLot) {
            if (
                $lot->origin_source_type === 'inventory_operation'
                && is_int($lot->origin_source_id)
            ) {
                $movement = InventoryMovement::query()
                    ->where('source_type', 'inventory_operation')
                    ->where('source_id', $lot->origin_source_id)
                    ->when(
                        is_int($lot->origin_source_line_id),
                        fn ($query) => $query->where('source_line_id', $lot->origin_source_line_id),
                    )
                    ->where('movement_type', MovementType::Receipt->value)
                    ->lockForUpdate()
                    ->first();
            }
        }

        if (! $movement instanceof InventoryMovement) {
            $movement = InventoryMovement::query()
                ->where('product_variant_id', $change->product_variant_id)
                ->where('warehouse_id', $change->warehouse_id)
                ->where('movement_type', MovementType::Receipt->value)
                ->where('source_type', 'inventory_operation')
                ->when(
                    is_int($change->inventory_lot_id),
                    fn ($query) => $query->where('inventory_lot_id', $change->inventory_lot_id),
                )
                ->where(function ($query): void {
                    $query->where('stock_condition_to', StockCondition::Quarantine->value)
                        ->orWhereNull('stock_condition_to');
                })
                ->latest('id')
                ->lockForUpdate()
                ->first();
        }

        if (
            ! $movement instanceof InventoryMovement
            || ! is_int($movement->source_id)
        ) {
            throw QuarantineDispositionRejected::because(
                'return-to-supplier requires traceable receipt provenance',
            );
        }

        $receipt = InventoryOperation::query()
            ->lockForUpdate()
            ->find($movement->source_id);

        if (
            ! $receipt instanceof InventoryOperation
            || $receipt->operation_type !== OperationType::Receipt
            || $receipt->stage !== OperationStage::Done
            || ! is_int($receipt->supplier_id)
        ) {
            throw QuarantineDispositionRejected::because(
                'the receipt provenance does not identify a completed supplier receipt',
            );
        }

        $supplier = Supplier::query()->lockForUpdate()->find($receipt->supplier_id);

        if (! $supplier instanceof Supplier) {
            throw QuarantineDispositionRejected::because('the originating supplier no longer exists');
        }

        $receiptLine = null;

        if (is_int($movement->source_line_id)) {
            $candidate = InventoryOperationLine::query()->lockForUpdate()->find($movement->source_line_id);

            if ($candidate instanceof InventoryOperationLine) {
                $receiptLine = $candidate;
            }
        }

        return [$supplier, $receipt, $receiptLine];
    }

    /** @param array<string,mixed>|null $balanceBefore */
    private function auditPosted(
        InventoryConditionChange $change,
        User $actor,
        ?array $balanceBefore,
    ): void {
        activity()
            ->performedOn($change)
            ->causedBy($actor)
            ->withChanges([
                'old' => $balanceBefore ?? [],
                'attributes' => [
                    'status' => InventoryConditionChangeStatus::Posted->value,
                    'condition_from' => $change->condition_from->value,
                    'condition_to' => $change->condition_to->value,
                    'base_quantity' => (string) $change->base_quantity,
                    'disposition' => $change->disposition?->value,
                ],
            ])
            ->withProperties([
                'source_channel' => 'dashboard',
                'ip_address' => request()->ip(),
                'inventory_movement_id' => $change->inventory_movement_id,
                'supplier_return_id' => $change->supplier_return_id,
            ])
            ->log('inventory.condition_change.posted');
    }

    /** @return numeric-string */
    private function positiveQuantity(string $quantity): string
    {
        if (
            ! is_numeric($quantity)
            || preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/D', $quantity) !== 1
        ) {
            throw QuarantineDispositionRejected::because(
                'quantity must be an exact base-UOM decimal with at most six places',
            );
        }

        $normalized = bcadd($quantity, '0', self::QUANTITY_SCALE);

        if (bccomp($normalized, '0', self::QUANTITY_SCALE) <= 0) {
            throw QuarantineDispositionRejected::because('quantity must be positive');
        }

        return $normalized;
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
