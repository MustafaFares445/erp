<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryPostingCommand;
use App\Enums\AdjustmentStatus;
use App\Enums\ConditionChangeReason;
use App\Enums\InventoryPostingBalanceMode;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Exceptions\Domain\SelfConfirmationRejected;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentItem;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Concerns\EnforcesMakerChecker;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class InventoryAdjustmentService
{
    use EnforcesMakerChecker;

    public function __construct(
        private InventoryAlertService $inventoryAlertService,
        private InventoryPostingService $inventoryPostingService,
        private InventoryLotService $inventoryLotService,
        private ProductTypeGuard $productTypeGuard,
    ) {}

    public function createCorrection(
        InventoryAdjustment $original,
        User $actor,
        string $reason,
    ): InventoryAdjustment {
        return DB::transaction(function () use ($original, $actor, $reason): InventoryAdjustment {
            $originalKey = $original->getKey();

            if (! is_int($originalKey)) {
                throw new \LogicException('Inventory adjustment identifiers must be integers.');
            }

            $locked = InventoryAdjustment::query()
                ->lockForUpdate()
                ->findOrFail($originalKey);

            if (! $locked->isConfirmed()) {
                throw new DomainException(__('admin.inventory.adjustment.errors.correction_requires_confirmed_origin'));
            }

            $reason = mb_trim($reason);

            if ($reason === '') {
                throw new DomainException(__('admin.inventory.adjustment.errors.correction_reason_required'));
            }

            $correction = InventoryAdjustment::query()->forceCreate([
                'warehouse_id' => $locked->warehouse_id,
                'corrects_adjustment_id' => $locked->getKey(),
                'reason' => $reason,
                'reason_category' => $locked->reason_category ?? ConditionChangeReason::Other,
                'status' => AdjustmentStatus::Draft,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($correction)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'ip_address' => request()->ip(),
                    'corrects_adjustment_id' => $locked->getKey(),
                ])
                ->log('inventory.adjustment.correction_created');

            return $correction->refresh();
        }, attempts: 5);
    }

    public function confirm(InventoryAdjustment $adjustment, User $actor): void
    {
        DB::transaction(function () use ($adjustment, $actor): void {
            $adjustmentKey = $adjustment->getKey();

            if (! is_int($adjustmentKey)) {
                throw new \LogicException('Inventory adjustment identifiers must be integers.');
            }

            $locked = InventoryAdjustment::query()
                ->with('warehouse')
                ->lockForUpdate()
                ->findOrFail($adjustmentKey);

            if ($locked->status !== AdjustmentStatus::Draft) {
                throw new DomainException(__('admin.inventory.adjustment.errors.not_draft'));
            }

            $makerId = is_numeric($locked->created_by) ? (int) $locked->created_by : null;

            if ($this->sameActor($makerId, $actor)) {
                throw SelfConfirmationRejected::forAdjustment($locked);
            }

            if (! $locked->warehouse instanceof Warehouse || ! $locked->warehouse->is_active) {
                throw new DomainException(__('admin.inventory.adjustment.errors.inactive_warehouse'));
            }

            $items = $locked->items()->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new DomainException(__('admin.inventory.adjustment.errors.no_items'));
            }

            $oldValuesItems = [];
            $newValuesItems = [];
            $commands = [];

            foreach ($items as $item) {
                if (! Package::belongsToWarehouse($item->package_id, $locked->warehouse_id)) {
                    throw new DomainException(__('admin.package.errors.warehouse_mismatch'));
                }

                [$oldQuantity, $difference, $command] = $this->prepareItem(
                    $item,
                    $locked,
                    $actor,
                );

                $oldValuesItems[] = [
                    'product_variant_id' => $item->product_variant_id,
                    'stock_condition' => $item->stock_condition->value,
                    'old_quantity' => $oldQuantity,
                ];
                $newValuesItems[] = [
                    'product_variant_id' => $item->product_variant_id,
                    'stock_condition' => $item->stock_condition->value,
                    'new_quantity' => (float) $item->new_quantity,
                    'difference' => $difference,
                ];
                $commands[] = $command;
            }

            foreach ($this->inventoryPostingService->postMany($commands) as $posting) {
                $this->inventoryAlertService->syncStock($posting->stock);
            }

            $adjustmentNumber = $this->nextAdjustmentNumber();

            $locked->forceFill([
                'adjustment_number' => $adjustmentNumber,
                'status' => AdjustmentStatus::Confirmed,
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => 'draft', 'items' => $oldValuesItems],
                    'attributes' => ['status' => 'confirmed', 'adjustment_number' => $adjustmentNumber, 'items' => $newValuesItems],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('inventory.adjustment.confirmed');
        });
    }

    /**
     * @return array{0: float, 1: float, 2: InventoryPostingCommand}
     */
    private function prepareItem(
        InventoryAdjustmentItem $item,
        InventoryAdjustment $adjustment,
        User $actor,
    ): array {
        $variant = ProductVariant::query()
            ->with(['product', 'unit'])
            ->lockForUpdate()
            ->findOrFail($item->product_variant_id);

        if (! $variant->isOperational()) {
            throw new DomainException(__('admin.inventory.adjustment.errors.inactive_variant'));
        }

        $condition = $this->itemCondition($item);
        $newQuantity = $this->quantity((string) $item->new_quantity);

        if ($condition === StockCondition::Disposed) {
            throw new DomainException('Disposed stock cannot be adjusted because it is not a materialized inventory condition.');
        }

        if (bccomp($newQuantity, '0', 6) < 0) {
            throw new DomainException(__('admin.inventory.balance.errors.negative_on_hand'));
        }

        if (bccomp($newQuantity, '0', 6) > 0) {
            $this->productTypeGuard->assertQuantity($variant, (float) $newQuantity, $variant->unit);
        }

        $tracksBatches = $variant->productType()?->tracksBatches() === true;
        $tracksSerials = $variant->productType()?->tracksSerials() === true;

        $lot = $this->lockedLot(
            $item,
            $variant,
            (int) $adjustment->warehouse_id,
            $condition,
            $tracksBatches,
        );
        $serializedUnit = $this->lockedSerializedUnit(
            $item,
            $variant,
            (int) $adjustment->warehouse_id,
            $condition,
            $newQuantity,
            $tracksSerials,
        );

        if (
            $lot instanceof InventoryLot
            && $serializedUnit instanceof SerializedInventoryUnit
            && $serializedUnit->inventory_lot_id !== $lot->getKey()
        ) {
            throw new DomainException(__('admin.inventory.adjustment.errors.invalid_serial'));
        }

        $stock = InventoryStock::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $adjustment->warehouse_id)
            ->lockForUpdate()
            ->first();

        $oldQuantity = $serializedUnit instanceof SerializedInventoryUnit
            ? $this->serializedOldQuantity(
                $serializedUnit,
                (int) $adjustment->warehouse_id,
                $condition,
            )
            : ($lot instanceof InventoryLot
                ? $this->quantity(number_format(
                    $lot->conditionOnHandQuantity(
                        $condition,
                        (int) $adjustment->warehouse_id,
                    ),
                    6,
                    '.',
                    '',
                ))
                : $this->quantity(number_format(
                    $stock?->conditionOnHandQuantity($condition) ?? 0.0,
                    6,
                    '.',
                    '',
                )));

        $difference = bcsub($newQuantity, $oldQuantity, 6);

        if ($serializedUnit instanceof SerializedInventoryUnit && ! in_array($difference, ['-1.000000', '0.000000', '1.000000'], true)) {
            throw new DomainException(__('admin.inventory.adjustment.errors.serial_difference'));
        }

        $item->forceFill([
            'old_quantity' => $oldQuantity,
            'difference' => $difference,
        ])->save();

        return [
            (float) $oldQuantity,
            (float) $difference,
            $this->postingCommand(
                $item,
                $adjustment,
                $actor,
                $difference,
                $stock instanceof InventoryStock,
                $variant,
                $lot,
                $serializedUnit,
                $condition,
            ),
        ];
    }

    private function lockedLot(
        InventoryAdjustmentItem $item,
        ProductVariant $variant,
        int $warehouseId,
        StockCondition $condition,
        bool $required,
    ): ?InventoryLot {
        if (! $required && $item->inventory_lot_id === null) {
            return null;
        }

        if ($item->inventory_lot_id === null) {
            throw new DomainException(__('admin.inventory.lot.errors.required'));
        }

        $lot = InventoryLot::query()->lockForUpdate()->find($item->inventory_lot_id);

        if (
            ! $lot instanceof InventoryLot
            || $lot->canonical_inventory_lot_id !== null
            || $lot->product_variant_id !== $variant->getKey()
            || ! $this->inventoryLotService->conditionBalanceForUpdate(
                $lot,
                $warehouseId,
                $condition,
            ) instanceof InventoryLotBalance
        ) {
            throw new DomainException(__('admin.inventory.lot.errors.required'));
        }

        return $lot;
    }

    private function lockedSerializedUnit(
        InventoryAdjustmentItem $item,
        ProductVariant $variant,
        int $warehouseId,
        StockCondition $condition,
        string $newQuantity,
        bool $required,
    ): ?SerializedInventoryUnit {
        if (! $required && $item->serialized_inventory_unit_id === null) {
            return null;
        }

        if ($item->serialized_inventory_unit_id === null) {
            throw new DomainException(__('admin.inventory.adjustment.errors.invalid_serial'));
        }

        $unit = SerializedInventoryUnit::query()->lockForUpdate()->find($item->serialized_inventory_unit_id);

        if (! $unit instanceof SerializedInventoryUnit || $unit->product_variant_id !== $variant->getKey()) {
            throw new DomainException(__('admin.inventory.adjustment.errors.invalid_serial'));
        }

        $presentStatus = $condition === StockCondition::Damaged
            ? SerializedInventoryUnitStatus::Damaged
            : SerializedInventoryUnitStatus::Available;

        if ($newQuantity === '0.000000') {
            if (
                $unit->status !== $presentStatus
                || $unit->warehouse_id !== $warehouseId
                || $unit->stock_condition !== $condition
            ) {
                throw new DomainException(__('admin.inventory.adjustment.errors.invalid_serial'));
            }
        } elseif ($newQuantity === '1.000000') {
            $isCurrent = $unit->status === $presentStatus
                && $unit->warehouse_id === $warehouseId
                && $unit->stock_condition === $condition;
            $isAdjustedOut = $unit->status === SerializedInventoryUnitStatus::AdjustedOut
                && $unit->warehouse_id === null
                && $unit->stock_condition === $condition;

            if (! $isCurrent && ! $isAdjustedOut) {
                throw new DomainException(__('admin.inventory.adjustment.errors.invalid_serial'));
            }
        } else {
            throw new DomainException(__('admin.inventory.adjustment.errors.serial_difference'));
        }

        return $unit;
    }

    /** @return numeric-string */
    private function serializedOldQuantity(
        SerializedInventoryUnit $unit,
        int $warehouseId,
        StockCondition $condition,
    ): string {
        $presentStatus = $condition === StockCondition::Damaged
            ? SerializedInventoryUnitStatus::Damaged
            : SerializedInventoryUnitStatus::Available;

        return $unit->status === $presentStatus
            && $unit->warehouse_id === $warehouseId
            && $unit->stock_condition === $condition
            ? '1.000000'
            : '0.000000';
    }

    /** @param numeric-string $difference */
    private function postingCommand(
        InventoryAdjustmentItem $item,
        InventoryAdjustment $adjustment,
        User $actor,
        string $difference,
        bool $stockExists,
        ProductVariant $variant,
        ?InventoryLot $lot,
        ?SerializedInventoryUnit $unit,
        StockCondition $condition,
    ): InventoryPostingCommand {
        $actorId = $actor->getKey();
        $adjustmentId = $adjustment->getKey();
        $itemId = $item->getKey();

        if (! is_int($actorId) || ! is_int($adjustmentId) || ! is_int($itemId)) {
            throw new \LogicException('Inventory adjustment identifiers must be integers.');
        }

        $serializedInventoryUnitId = $unit?->getKey();
        $inventoryLotId = $lot?->getKey();

        if (
            ($serializedInventoryUnitId !== null && ! is_int($serializedInventoryUnitId))
            || ($inventoryLotId !== null && ! is_int($inventoryLotId))
        ) {
            throw new \LogicException('Inventory adjustment identifiers must be integers.');
        }

        $variantUnitId = $variant->unit_id;

        if (! is_int($variantUnitId)) {
            throw new \LogicException('Inventory adjustment variants require an integer base-unit identifier.');
        }

        $hasQuantityChange = bccomp($difference, '0', 6) !== 0;
        $transactionQuantity = $hasQuantityChange
            ? (bccomp($difference, '0', 6) < 0 ? bcsub('0', $difference, 6) : $difference)
            : null;

        $serializedTargetStatus = null;
        $serializedWarehouseSpecified = false;
        $serializedTargetWarehouseId = null;
        $serializedTargetCustodyType = null;
        $serializedTargetCustodyReferenceType = null;
        $serializedTargetCustodyReferenceId = null;

        if ($unit instanceof SerializedInventoryUnit && $difference === '-1.000000') {
            $serializedTargetStatus = SerializedInventoryUnitStatus::AdjustedOut;
            $serializedWarehouseSpecified = true;
            $serializedTargetWarehouseId = null;
            $serializedTargetCustodyType = SerializedCustodyType::Unknown;
            $serializedTargetCustodyReferenceType = 'adjustment';
            $serializedTargetCustodyReferenceId = $adjustmentId;
        } elseif ($unit instanceof SerializedInventoryUnit && $difference === '1.000000') {
            $serializedTargetStatus = $condition === StockCondition::Damaged
                ? SerializedInventoryUnitStatus::Damaged
                : SerializedInventoryUnitStatus::Available;
            $serializedWarehouseSpecified = true;
            $serializedTargetWarehouseId = (int) $adjustment->warehouse_id;
            $serializedTargetCustodyType = SerializedCustodyType::Warehouse;
            $serializedTargetCustodyReferenceType = 'warehouse';
            $serializedTargetCustodyReferenceId = (int) $adjustment->warehouse_id;
        }

        return new InventoryPostingCommand(
            productVariantId: (int) $item->product_variant_id,
            warehouseId: (int) $adjustment->warehouse_id,
            onHandBaseQuantityDelta: $difference,
            reservedBaseQuantityDelta: '0',
            damagedBaseQuantityDelta: $condition === StockCondition::Damaged ? $difference : '0',
            movementType: MovementType::Adjustment,
            movementBaseQuantityDelta: $difference,
            sourceType: 'adjustment',
            sourceId: $adjustmentId,
            actorId: $actorId,
            notes: $adjustment->reason,
            serializedInventoryUnitId: $serializedInventoryUnitId,
            idempotencyKey: sprintf('inventory-adjustment:%d:%d', $adjustmentId, $itemId),
            balanceMode: $stockExists ? InventoryPostingBalanceMode::RequireExisting : InventoryPostingBalanceMode::CreateIfMissing,
            inventoryLotId: $inventoryLotId,
            packageId: $item->package_id,
            sourceLineType: 'inventory_adjustment_item',
            sourceLineId: $itemId,
            transactionQuantity: $transactionQuantity,
            transactionUnitId: $hasQuantityChange ? $variantUnitId : null,
            conversionFactorSnapshot: $hasQuantityChange ? '1.000000' : null,
            baseQuantityDelta: $hasQuantityChange ? $difference : null,
            lotOnHandBaseQuantityDelta: $lot instanceof InventoryLot ? $difference : null,
            serializedTargetStatus: $serializedTargetStatus,
            serializedWarehouseSpecified: $serializedWarehouseSpecified,
            serializedTargetWarehouseId: $serializedTargetWarehouseId,
            serializedTargetCustodyType: $serializedTargetCustodyType,
            serializedTargetCustodyReferenceType: $serializedTargetCustodyReferenceType,
            serializedTargetCustodyReferenceId: $serializedTargetCustodyReferenceId,
            stockCondition: $condition,
            serializedTargetStockCondition: $unit instanceof SerializedInventoryUnit ? $condition : null,
        );
    }

    private function itemCondition(InventoryAdjustmentItem $item): StockCondition
    {
        $condition = $item->stock_condition;

        if (! $condition instanceof StockCondition) {
            throw new DomainException('Inventory adjustment items require an explicit stock condition.');
        }

        return $condition;
    }

    /** @return numeric-string */
    private function quantity(string $quantity): string
    {
        if (! is_numeric($quantity) || preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d{1,6})?$/D', $quantity) !== 1) {
            throw new DomainException('Inventory adjustment quantities must be exact base-UOM decimals with at most six places.');
        }

        return bcadd($quantity, '0', 6);
    }

    private function nextAdjustmentNumber(): string
    {
        $maxNumber = InventoryAdjustment::query()
            ->whereNotNull('adjustment_number')
            ->lockForUpdate()
            ->max('adjustment_number');

        $nextSequence = is_string($maxNumber) ? ((int) mb_substr($maxNumber, 4) + 1) : 1;

        return sprintf('ADJ-%06d', $nextSequence);
    }
}
