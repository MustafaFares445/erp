<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\StockDamageData;
use App\Enums\MovementType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class InventoryDamageService
{
    public function __construct(
        private InventoryBalanceService $inventoryBalanceService,
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
            $lockedStock = InventoryStock::query()
                ->lockForUpdate()
                ->findOrFail($this->stockId($stock));
            $unit = $this->serializedUnitForUpdate($lockedStock, $data, $operation);
            $before = $this->balanceValues($lockedStock);
            $updatedStock = match ($operation) {
                MovementType::Damage => $this->inventoryBalanceService->damage($lockedStock, $data->quantity),
                MovementType::DamageRecovery => $this->inventoryBalanceService->recoverDamage($lockedStock, $data->quantity),
                MovementType::Disposal => $this->inventoryBalanceService->disposeDamage($lockedStock, $data->quantity),
                default => throw new LogicException('Unsupported damage operation.'),
            };

            $this->transitionSerializedUnit($unit, $operation);
            $this->recordMovement($updatedStock, $data, $actor, $operation);
            activity()
                ->performedOn($updatedStock)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $before,
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

    private function serializedUnitForUpdate(
        InventoryStock $stock,
        StockDamageData $data,
        MovementType $operation,
    ): ?SerializedInventoryUnit {
        if ($data->serializedInventoryUnitId === null) {
            return null;
        }

        $unit = SerializedInventoryUnit::query()
            ->lockForUpdate()
            ->findOrFail($data->serializedInventoryUnitId);
        $requiredStatus = $operation === MovementType::Damage
            ? SerializedInventoryUnitStatus::Available
            : SerializedInventoryUnitStatus::Damaged;

        if (
            $unit->product_variant_id !== $stock->product_variant_id
            || $unit->warehouse_id !== $stock->warehouse_id
            || $unit->status !== $requiredStatus
        ) {
            throw new DomainException(__('admin.inventory.damage.errors.invalid_serial'));
        }

        return $unit;
    }

    private function transitionSerializedUnit(?SerializedInventoryUnit $unit, MovementType $operation): void
    {
        if (! $unit instanceof SerializedInventoryUnit) {
            return;
        }

        $unit->forceFill(match ($operation) {
            MovementType::Damage => ['status' => SerializedInventoryUnitStatus::Damaged],
            MovementType::DamageRecovery => ['status' => SerializedInventoryUnitStatus::Available],
            MovementType::Disposal => [
                'status' => SerializedInventoryUnitStatus::Disposed,
                'warehouse_id' => null,
            ],
            default => throw new LogicException('Unsupported serialized damage transition.'),
        })->save();
    }

    private function recordMovement(
        InventoryStock $stock,
        StockDamageData $data,
        User $actor,
        MovementType $operation,
    ): void {
        $quantity = $operation === MovementType::DamageRecovery
            ? $data->quantity
            : -$data->quantity;

        InventoryMovement::query()->forceCreate([
            'product_variant_id' => $stock->product_variant_id,
            'warehouse_id' => $stock->warehouse_id,
            'movement_type' => $operation,
            'quantity' => $quantity,
            'source_type' => 'stock_damage',
            'source_id' => $stock->getKey(),
            'serialized_inventory_unit_id' => $data->serializedInventoryUnitId,
            'status' => 'confirmed',
            'created_by' => $actor->getKey(),
            'notes' => $data->reason,
        ]);
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
}
