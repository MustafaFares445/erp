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
 * Moves a loaner serialized unit between warehouse custody and a customer's
 * temporary custody. Inventory owns custody: Support asks, this service
 * validates the unit under a lock and posts the movement through
 * {@see InventoryPostingService}, which is the only writer of custody.
 */
final readonly class InventoryEquipmentLoanService
{
    public const string SourceType = 'equipment_loan';

    public function __construct(private InventoryPostingService $posting) {}

    /** Warehouse custody -> customer temporary custody. */
    public function issue(SerializedInventoryUnit $unit, int $customerId, int $loanId, User $actor, ?string $notes = null): InventoryMovement
    {
        Gate::forUser($actor)->authorize(InventoryPermission::LoanManage->value);

        return DB::transaction(function () use ($unit, $customerId, $loanId, $actor, $notes): InventoryMovement {
            $locked = SerializedInventoryUnit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            $warehouseId = $locked->warehouse_id;

            if (
                $locked->status !== SerializedInventoryUnitStatus::Available
                || $locked->custody_type !== SerializedCustodyType::Warehouse
                || $locked->stock_condition !== StockCondition::Saleable
                || $warehouseId === null
            ) {
                throw new DomainException('Only an available, saleable, warehouse-held unit can be issued as a loaner.');
            }

            $this->assertNotLotTracked($locked);

            return $this->posting->post(new InventoryPostingCommand(
                productVariantId: $locked->product_variant_id,
                warehouseId: $warehouseId,
                onHandBaseQuantityDelta: '-1.000000',
                reservedBaseQuantityDelta: '0.000000',
                damagedBaseQuantityDelta: '0.000000',
                movementType: MovementType::LoanIssue,
                movementBaseQuantityDelta: '-1.000000',
                sourceType: self::SourceType,
                sourceId: $loanId,
                actorId: $actor->id,
                notes: $notes,
                serializedInventoryUnitId: $locked->id,
                idempotencyKey: sprintf('equipment-loan:%d:issue', $loanId),
                transactionQuantity: '1.000000',
                transactionUnitId: $this->unitId($locked),
                conversionFactorSnapshot: '1.000000',
                baseQuantityDelta: '-1.000000',
                serializedTargetStatus: SerializedInventoryUnitStatus::Delivered,
                serializedWarehouseSpecified: true,
                serializedTargetCustodyType: SerializedCustodyType::Customer,
                serializedTargetCustodyReferenceType: 'customer',
                serializedTargetCustodyReferenceId: $customerId,
            ))->movement;
        });
    }

    /** Customer temporary custody -> warehouse custody, in the inspected condition. */
    public function return(SerializedInventoryUnit $unit, int $warehouseId, StockCondition $condition, int $loanId, User $actor, ?string $notes = null): InventoryMovement
    {
        Gate::forUser($actor)->authorize(InventoryPermission::LoanManage->value);

        if (! in_array($condition, [StockCondition::Saleable, StockCondition::Damaged, StockCondition::Quarantine], true)) {
            throw new DomainException('A returned loaner must be inspected as saleable, damaged or quarantined.');
        }

        return DB::transaction(function () use ($unit, $warehouseId, $condition, $loanId, $actor, $notes): InventoryMovement {
            $locked = SerializedInventoryUnit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->custody_type !== SerializedCustodyType::Customer) {
                throw new DomainException('Only a unit in customer custody can be returned from a loan.');
            }

            return $this->posting->post(new InventoryPostingCommand(
                productVariantId: $locked->product_variant_id,
                warehouseId: $warehouseId,
                onHandBaseQuantityDelta: '1.000000',
                reservedBaseQuantityDelta: '0.000000',
                damagedBaseQuantityDelta: $condition === StockCondition::Damaged ? '1.000000' : '0.000000',
                movementType: MovementType::LoanReturn,
                movementBaseQuantityDelta: '1.000000',
                sourceType: self::SourceType,
                sourceId: $loanId,
                actorId: $actor->id,
                notes: $notes,
                serializedInventoryUnitId: $locked->id,
                idempotencyKey: sprintf('equipment-loan:%d:return', $loanId),
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

    private function assertNotLotTracked(SerializedInventoryUnit $unit): void
    {
        if ($unit->inventory_lot_id !== null) {
            throw new DomainException('Lot-tracked units cannot be issued as loaners.');
        }
    }

    private function unitId(SerializedInventoryUnit $unit): int
    {
        return ProductVariant::query()->findOrFail($unit->product_variant_id)->unit_id;
    }
}
