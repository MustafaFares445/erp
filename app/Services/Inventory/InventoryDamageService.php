<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryPostingCommand;
use App\Data\Inventory\StockDamageData;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class InventoryDamageService
{
    public function __construct(
        private InventoryPostingService $inventoryPostingService,
        private InventoryLotService $inventoryLotService,
        private InventoryAlertService $inventoryAlertService,
    ) {}

    public function damage(InventoryStock $stock, StockDamageData $data, User $actor): InventoryStock
    {
        return $this->execute($stock, $data, $actor, MovementType::Damage);
    }

    public function recover(InventoryStock $stock, StockDamageData $data, User $actor): InventoryStock
    {
        return $this->execute($stock, $data, $actor, MovementType::DamageRecovery);
    }

    public function dispose(InventoryStock $stock, StockDamageData $data, User $actor): InventoryStock
    {
        return $this->execute($stock, $data, $actor, MovementType::Disposal);
    }

    private function execute(
        InventoryStock $stock,
        StockDamageData $data,
        User $actor,
        MovementType $operation,
    ): InventoryStock {
        $this->validateInput($data);

        return DB::transaction(function () use ($stock, $data, $actor, $operation): InventoryStock {
            $this->stockId($stock);
            $lot = $this->validatedLot($stock, $data, $operation);
            $this->validateSerializedUnit($stock, $data, $operation);
            $posting = $this->inventoryPostingService->post($this->postingCommand($stock, $data, $actor, $operation, $lot));
            $updatedStock = $posting->stock;
            activity()
                ->performedOn($updatedStock)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $posting->balanceBefore->toAuditValues(),
                    'attributes' => [
                        ...$this->balanceValues($updatedStock),
                        'reason' => $data->reason,
                        'serialized_inventory_unit_id' => $data->serializedInventoryUnitId,
                    ],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log($this->auditAction($operation));
            $this->inventoryAlertService->syncStock($updatedStock);

            return $updatedStock;
        }, attempts: 5);
    }

    private function validateInput(StockDamageData $data): void
    {
        if ($data->quantity <= 0) {
            throw new DomainException(__('admin.inventory.balance.errors.invalid_quantity'));
        }

        if (mb_trim($data->reason) === '') {
            throw new DomainException(__('admin.inventory.damage.errors.reason_required'));
        }

        if ($data->serializedInventoryUnitId !== null && round($data->quantity, 3) !== 1.0) {
            throw new DomainException(__('admin.inventory.damage.errors.serial_quantity'));
        }
    }

    private function validatedLot(
        InventoryStock $stock,
        StockDamageData $data,
        MovementType $operation,
    ): ?InventoryLot {
        $variant = ProductVariant::query()->with('product')->findOrFail($stock->product_variant_id);
        $requiresLot = $variant->productType()?->tracksBatches() === true;

        if (! $requiresLot && $data->inventoryLotId === null) {
            return null;
        }

        if ($data->inventoryLotId === null) {
            throw new DomainException(__('admin.inventory.lot.errors.required'));
        }

        $lot = InventoryLot::query()->lockForUpdate()->find($data->inventoryLotId);

        $sourceCondition = $operation === MovementType::Damage
            ? StockCondition::Saleable
            : StockCondition::Damaged;

        if (
            ! $lot instanceof InventoryLot
            || $lot->canonical_inventory_lot_id !== null
            || $lot->product_variant_id !== $stock->product_variant_id
            || ! $this->inventoryLotService->conditionBalanceForUpdate(
                $lot,
                (int) $stock->warehouse_id,
                $sourceCondition,
            ) instanceof InventoryLotBalance
        ) {
            throw new DomainException(__('admin.inventory.lot.errors.required'));
        }

        return $lot;
    }

    private function validateSerializedUnit(
        InventoryStock $stock,
        StockDamageData $data,
        MovementType $operation,
    ): void {
        if ($data->serializedInventoryUnitId === null) {
            return;
        }

        $unit = SerializedInventoryUnit::query()->lockForUpdate()->find($data->serializedInventoryUnitId);

        if (! $unit instanceof SerializedInventoryUnit) {
            throw new DomainException(__('admin.inventory.damage.errors.invalid_serial'));
        }

        $requiredStatus = $operation === MovementType::Damage
            ? SerializedInventoryUnitStatus::Available
            : SerializedInventoryUnitStatus::Damaged;
        $requiredCondition = $operation === MovementType::Damage
            ? StockCondition::Saleable
            : StockCondition::Damaged;

        if (
            $unit->product_variant_id !== $stock->product_variant_id
            || $unit->warehouse_id !== $stock->warehouse_id
            || $unit->status !== $requiredStatus
            || $unit->stock_condition !== $requiredCondition
            || (
                $data->inventoryLotId !== null
                && $unit->inventory_lot_id !== $data->inventoryLotId
            )
        ) {
            throw new DomainException(__('admin.inventory.damage.errors.invalid_serial'));
        }
    }

    /** @return array{on_hand_quantity: float, reserved_quantity: float, damaged_quantity: float, available_quantity: float} */
    private function balanceValues(InventoryStock $stock): array
    {
        return [
            'on_hand_quantity' => (float) $stock->on_hand_quantity,
            'reserved_quantity' => (float) $stock->reserved_quantity,
            'damaged_quantity' => (float) $stock->damaged_quantity,
            'available_quantity' => (float) $stock->available_quantity,
        ];
    }

    private function auditAction(MovementType $operation): string
    {
        return match ($operation) {
            MovementType::Damage => 'inventory.stock.damaged',
            MovementType::DamageRecovery => 'inventory.stock.damage_recovered',
            MovementType::Disposal => 'inventory.stock.disposed',
            default => throw new LogicException('Unsupported damage audit operation.'),
        };
    }

    private function stockId(InventoryStock $stock): int
    {
        $key = $stock->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory stocks must use integer identifiers.');
        }

        return $key;
    }

    private function postingCommand(
        InventoryStock $stock,
        StockDamageData $data,
        User $actor,
        MovementType $operation,
        ?InventoryLot $lot,
    ): InventoryPostingCommand {
        $quantity = number_format($data->quantity, 3, '.', '');
        $actorId = $actor->getKey();

        if (! is_int($actorId)) {
            throw new LogicException('Users must use integer identifiers.');
        }

        return new InventoryPostingCommand(
            productVariantId: $this->stockForeignId($stock, 'product_variant_id'),
            warehouseId: $this->stockForeignId($stock, 'warehouse_id'),
            onHandBaseQuantityDelta: $operation === MovementType::Disposal ? '-'.$quantity : '0.000',
            reservedBaseQuantityDelta: '0.000',
            damagedBaseQuantityDelta: match ($operation) {
                MovementType::Damage => $quantity,
                MovementType::DamageRecovery, MovementType::Disposal => '-'.$quantity,
                default => throw new LogicException('Unsupported damage operation.'),
            },
            movementType: $operation,
            movementBaseQuantityDelta: $operation === MovementType::DamageRecovery ? $quantity : '-'.$quantity,
            sourceType: 'stock_damage',
            sourceId: $this->stockId($stock),
            actorId: $actorId,
            notes: $data->reason,
            serializedInventoryUnitId: $data->serializedInventoryUnitId,
            inventoryLotId: $lot?->getKey(),
            lotOnHandBaseQuantityDelta: $operation === MovementType::Disposal && $lot instanceof InventoryLot ? '-'.$quantity : null,
            serializedTargetStatus: $data->serializedInventoryUnitId === null ? null : match ($operation) {
                MovementType::Damage => SerializedInventoryUnitStatus::Damaged,
                MovementType::DamageRecovery => SerializedInventoryUnitStatus::Available,
                MovementType::Disposal => SerializedInventoryUnitStatus::Disposed,
                default => null,
            },
            serializedWarehouseSpecified: $operation === MovementType::Disposal && $data->serializedInventoryUnitId !== null,
            serializedTargetCustodyType: match ($operation) {
                MovementType::Damage, MovementType::DamageRecovery => $data->serializedInventoryUnitId === null ? null : SerializedCustodyType::Warehouse,
                MovementType::Disposal => $data->serializedInventoryUnitId === null ? null : SerializedCustodyType::Disposed,
                default => null,
            },
            serializedTargetCustodyReferenceType: $data->serializedInventoryUnitId === null ? null : (
                $operation === MovementType::Disposal ? 'stock_damage' : 'warehouse'
            ),
            serializedTargetCustodyReferenceId: $data->serializedInventoryUnitId === null ? null : (
                $operation === MovementType::Disposal ? $this->stockId($stock) : $this->stockForeignId($stock, 'warehouse_id')
            ),
            conditionFrom: match ($operation) {
                MovementType::Damage => StockCondition::Saleable,
                MovementType::DamageRecovery, MovementType::Disposal => StockCondition::Damaged,
                default => null,
            },
            conditionTo: match ($operation) {
                MovementType::Damage => StockCondition::Damaged,
                MovementType::DamageRecovery => StockCondition::Saleable,
                MovementType::Disposal => StockCondition::Disposed,
                default => null,
            },
            conditionTransferBaseQuantity: $quantity,
            serializedTargetStockCondition: $data->serializedInventoryUnitId === null ? null : match ($operation) {
                MovementType::Damage => StockCondition::Damaged,
                MovementType::DamageRecovery => StockCondition::Saleable,
                MovementType::Disposal => StockCondition::Disposed,
                default => null,
            },
        );
    }

    private function stockForeignId(InventoryStock $stock, string $attribute): int
    {
        $value = $stock->getAttribute($attribute);

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new LogicException('Inventory stocks must have positive integer '.$attribute.'.');
    }
}
