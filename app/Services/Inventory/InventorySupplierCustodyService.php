<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryPostingCommand;
use App\Enums\InventoryPermission;
use App\Enums\InventoryPostingBalanceMode;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Moves a serialized unit into supplier custody for an external repair (RMA)
 * and back into a warehouse afterwards, reusing
 * {@see SerializedCustodyType::Supplier} and
 * {@see SerializedInventoryUnitStatus::ReturnedToSupplier}. Inventory owns
 * custody; Support asks through this service and never writes it directly.
 */
final readonly class InventorySupplierCustodyService
{
    public const string SourceType = 'maintenance_external_repair';

    public function __construct(private InventoryPostingService $posting) {}

    /** Warehouse custody -> supplier custody. */
    public function shipToSupplier(SerializedInventoryUnit $unit, int $supplierId, int $repairId, User $actor, ?string $notes = null): InventoryMovement
    {
        Gate::forUser($actor)->authorize(InventoryPermission::SupplierCustodyManage->value);

        return DB::transaction(function () use ($unit, $supplierId, $repairId, $actor, $notes): InventoryMovement {
            $locked = SerializedInventoryUnit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            $warehouseId = $locked->warehouse_id;

            if (
                $locked->custody_type !== SerializedCustodyType::Warehouse
                || $warehouseId === null
                || ! in_array($locked->status, [SerializedInventoryUnitStatus::Available, SerializedInventoryUnitStatus::Damaged], true)
            ) {
                throw new DomainException('Only a unit held in a warehouse can be shipped to a supplier; receive it into the warehouse first.');
            }

            if ($locked->inventory_lot_id !== null) {
                throw new DomainException('Lot-tracked units cannot be shipped for an external repair.');
            }

            $damaged = $locked->stock_condition === StockCondition::Damaged;

            return $this->posting->post(new InventoryPostingCommand(
                productVariantId: $locked->product_variant_id,
                warehouseId: $warehouseId,
                onHandBaseQuantityDelta: '-1.000000',
                reservedBaseQuantityDelta: '0.000000',
                damagedBaseQuantityDelta: $damaged ? '-1.000000' : '0.000000',
                movementType: MovementType::SupplierRepairOut,
                movementBaseQuantityDelta: '-1.000000',
                sourceType: self::SourceType,
                sourceId: $repairId,
                actorId: $actor->id,
                notes: $notes,
                serializedInventoryUnitId: $locked->id,
                idempotencyKey: sprintf('external-repair:%d:ship', $repairId),
                transactionQuantity: '1.000000',
                transactionUnitId: $this->unitId($locked),
                conversionFactorSnapshot: '1.000000',
                baseQuantityDelta: '-1.000000',
                serializedTargetStatus: SerializedInventoryUnitStatus::ReturnedToSupplier,
                serializedWarehouseSpecified: true,
                serializedTargetCustodyType: SerializedCustodyType::Supplier,
                serializedTargetCustodyReferenceType: 'supplier',
                serializedTargetCustodyReferenceId: $supplierId,
                stockCondition: $damaged ? StockCondition::Damaged : StockCondition::Saleable,
            ))->movement;
        });
    }

    /** Supplier custody -> warehouse custody, in the inspected condition. */
    public function receiveFromSupplier(SerializedInventoryUnit $unit, int $warehouseId, StockCondition $condition, int $repairId, User $actor, ?string $notes = null): InventoryMovement
    {
        Gate::forUser($actor)->authorize(InventoryPermission::SupplierCustodyManage->value);

        if (! in_array($condition, [StockCondition::Saleable, StockCondition::Damaged, StockCondition::Quarantine], true)) {
            throw new DomainException('A unit returned from a supplier must be inspected as saleable, damaged or quarantined.');
        }

        return DB::transaction(function () use ($unit, $warehouseId, $condition, $repairId, $actor, $notes): InventoryMovement {
            $locked = SerializedInventoryUnit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->custody_type !== SerializedCustodyType::Supplier) {
                throw new DomainException('Only a unit in supplier custody can be received back from a supplier.');
            }

            return $this->posting->post(new InventoryPostingCommand(
                productVariantId: $locked->product_variant_id,
                warehouseId: $warehouseId,
                onHandBaseQuantityDelta: '1.000000',
                reservedBaseQuantityDelta: '0.000000',
                damagedBaseQuantityDelta: $condition === StockCondition::Damaged ? '1.000000' : '0.000000',
                movementType: MovementType::SupplierRepairIn,
                movementBaseQuantityDelta: '1.000000',
                sourceType: self::SourceType,
                sourceId: $repairId,
                actorId: $actor->id,
                notes: $notes,
                serializedInventoryUnitId: $locked->id,
                idempotencyKey: sprintf('external-repair:%d:return', $repairId),
                balanceMode: InventoryPostingBalanceMode::CreateIfMissing,
                transactionQuantity: '1.000000',
                transactionUnitId: $this->unitId($locked),
                conversionFactorSnapshot: '1.000000',
                baseQuantityDelta: '1.000000',
                serializedTargetStatus: $condition === StockCondition::Damaged ? SerializedInventoryUnitStatus::Damaged : SerializedInventoryUnitStatus::Available,
                serializedWarehouseSpecified: true,
                serializedTargetWarehouseId: $warehouseId,
                serializedTargetCustodyType: SerializedCustodyType::Warehouse,
                serializedTargetCustodyReferenceType: 'warehouse',
                serializedTargetCustodyReferenceId: $warehouseId,
                stockCondition: $condition,
                serializedTargetStockCondition: $condition,
            ))->movement;
        });
    }

    private function unitId(SerializedInventoryUnit $unit): int
    {
        return ProductVariant::query()->findOrFail($unit->product_variant_id)->unit_id;
    }
}
