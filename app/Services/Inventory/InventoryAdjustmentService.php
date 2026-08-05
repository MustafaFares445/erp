<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\AdjustmentStatus;
use App\Enums\MovementType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The **only** code path that mutates stock as a result of an adjustment
 * (constitution Principle III; FR-009). The Filament layer never touches
 * {@see InventoryStock}/{@see InventoryMovement} directly — enforced by the
 * FI-0 architecture guard in tests/Unit/ArchTest.php (research R4) — so
 * every write physically has to flow through here.
 *
 * @see /specs/003-stock-adjustments/contracts/adjustment-service.md
 */
final readonly class InventoryAdjustmentService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private InventoryAlertService $inventoryAlertService,
        private InventoryBalanceService $inventoryBalanceService,
        private ProductTypeGuard $productTypeGuard,
    ) {}

    /**
     * Apply a draft adjustment: for each item write one adjustment movement,
     * update the (variant, warehouse) balance by the line difference, assign
     * the adjustment number, mark the document confirmed, and write one
     * audit record — atomically. Throws on any domain violation, leaving no
     * partial state.
     *
     * @throws DomainException invalid state / inactive warehouse / negative result
     */
    public function confirm(InventoryAdjustment $adjustment, User $actor): void
    {
        DB::transaction(function () use ($adjustment, $actor): void {
            /** @var InventoryAdjustment $locked */
            $locked = InventoryAdjustment::query()
                ->with('warehouse')
                ->lockForUpdate()
                ->findOrFail($adjustment->getKey());

            if ($locked->status !== AdjustmentStatus::Draft) {
                throw new DomainException(__('admin.inventory.adjustment.errors.not_draft'));
            }

            $warehouse = $locked->warehouse;

            if (! $warehouse instanceof Warehouse || ! $warehouse->is_active) {
                throw new DomainException(__('admin.inventory.adjustment.errors.inactive_warehouse'));
            }

            $items = $locked->items()->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new DomainException(__('admin.inventory.adjustment.errors.no_items'));
            }

            $oldValuesItems = [];
            $newValuesItems = [];

            foreach ($items as $item) {
                if (! Package::belongsToWarehouse($item->package_id, $locked->warehouse_id)) {
                    throw new DomainException(__('admin.package.errors.warehouse_mismatch'));
                }

                [$oldQuantity, $difference, $stock] = $this->applyItem($item, $locked->warehouse_id);
                $this->applySerializedUnit($item, $locked->warehouse_id, $difference);

                $oldValuesItems[] = [
                    'product_variant_id' => $item->product_variant_id,
                    'old_quantity' => $oldQuantity,
                ];
                $newValuesItems[] = [
                    'product_variant_id' => $item->product_variant_id,
                    'new_quantity' => (float) $item->new_quantity,
                    'difference' => $difference,
                ];

                InventoryMovement::query()->forceCreate([
                    'product_variant_id' => $item->product_variant_id,
                    'warehouse_id' => $locked->warehouse_id,
                    'movement_type' => MovementType::Adjustment,
                    'quantity' => $difference,
                    'source_type' => 'adjustment',
                    'source_id' => $locked->getKey(),
                    'serialized_inventory_unit_id' => $item->serialized_inventory_unit_id,
                    'package_id' => $item->package_id,
                    'status' => 'confirmed',
                    'created_by' => $actor->getKey(),
                    'notes' => $locked->reason,
                ]);

                $this->inventoryAlertService->syncStock($stock);
            }

            $adjustmentNumber = $this->nextAdjustmentNumber();

            $locked->forceFill([
                'adjustment_number' => $adjustmentNumber,
                'status' => AdjustmentStatus::Confirmed,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->auditLogger->log(
                action: 'inventory.adjustment.confirmed',
                entity: $locked,
                oldValues: ['status' => 'draft', 'items' => $oldValuesItems],
                newValues: ['status' => 'confirmed', 'adjustment_number' => $adjustmentNumber, 'items' => $newValuesItems],
                actor: $actor,
                sourceChannel: 'dashboard',
            );
        });
    }

    /**
     * Locks/reads the live `(variant, warehouse)` balance, finalizes the
     * item's `old_quantity`/`difference` from it, and upserts the balance.
     * A missing balance row is treated as zero and established (FR-012).
     *
     * @return array{0: float, 1: float, 2: InventoryStock} [oldQuantity, difference, stock]
     *
     * @throws DomainException when the resulting on-hand would be negative
     */
    private function applyItem(InventoryAdjustmentItem $item, int $warehouseId): array
    {
        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()->with(['product', 'unit'])->lockForUpdate()->findOrFail($item->product_variant_id);

        if (! $variant->isOperational()) {
            throw new DomainException(__('admin.inventory.adjustment.errors.inactive_variant'));
        }

        // Adjustments previously bypassed both the unit's decimal rule and the product type's
        // quantity rule entirely, so a count of 2.5 machines could be written straight to a
        // balance. A counted quantity of zero is legitimate here — it is how stock is written
        // off — so only a positive count is type-checked.
        if ((float) $item->new_quantity > 0) {
            $this->productTypeGuard->assertQuantity($variant, (float) $item->new_quantity, $variant->unit);
        }

        $stock = InventoryStock::query()
            ->where('product_variant_id', $item->product_variant_id)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        $oldQuantity = $stock instanceof InventoryStock ? (float) $stock->on_hand_quantity : 0.0;
        $newQuantity = (float) $item->new_quantity;
        $difference = $newQuantity - $oldQuantity;

        $item->old_quantity = $oldQuantity;
        $item->difference = $difference;
        $item->save();

        $stock = $this->inventoryBalanceService->adjustTo(
            $variant,
            $warehouseId,
            $newQuantity,
        );

        return [$oldQuantity, $difference, $stock];
    }

    /** @throws DomainException */
    private function applySerializedUnit(
        InventoryAdjustmentItem $item,
        int $warehouseId,
        float $difference,
    ): void {
        if ($item->serialized_inventory_unit_id === null) {
            return;
        }

        /** @var SerializedInventoryUnit $unit */
        $unit = SerializedInventoryUnit::query()
            ->lockForUpdate()
            ->findOrFail($item->serialized_inventory_unit_id);

        if ($unit->product_variant_id !== $item->product_variant_id) {
            throw new DomainException(__('admin.inventory.adjustment.errors.invalid_serial'));
        }

        if ($difference === -1.0) {
            $this->adjustSerializedUnitOut($unit, $warehouseId);

            return;
        }

        if ($difference === 1.0) {
            $this->adjustSerializedUnitIn($unit, $warehouseId);

            return;
        }

        throw new DomainException(__('admin.inventory.adjustment.errors.serial_difference'));
    }

    /** @throws DomainException */
    private function adjustSerializedUnitOut(SerializedInventoryUnit $unit, int $warehouseId): void
    {
        if (
            $unit->status !== SerializedInventoryUnitStatus::Available
            || $unit->warehouse_id !== $warehouseId
        ) {
            throw new DomainException(__('admin.inventory.adjustment.errors.invalid_serial'));
        }

        $unit->forceFill([
            'warehouse_id' => null,
            'status' => SerializedInventoryUnitStatus::AdjustedOut,
        ])->save();
    }

    /** @throws DomainException */
    private function adjustSerializedUnitIn(SerializedInventoryUnit $unit, int $warehouseId): void
    {
        if ($unit->status !== SerializedInventoryUnitStatus::AdjustedOut) {
            throw new DomainException(__('admin.inventory.adjustment.errors.invalid_serial'));
        }

        $unit->forceFill([
            'warehouse_id' => $warehouseId,
            'status' => SerializedInventoryUnitStatus::Available,
        ])->save();
    }

    /**
     * `ADJ-` + zero-padded sequential, derived from the locked max existing
     * number within the transaction (research R6). Zero-padded fixed-width
     * numbers sort identically as strings and numerically, so a plain SQL
     * `MAX()` is sufficient and lets the row lock scope to the rows involved.
     */
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
