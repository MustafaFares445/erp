<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryPostingCommand;
use App\Data\Inventory\InventoryPostingResult;
use App\Data\Inventory\StockDamageData;
use App\Enums\MovementType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryStock;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class InventoryDamageService
{
    public function __construct(
        private InventoryPostingService $inventoryPostingService,
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
            $posting = $this->inventoryPostingService->post($this->postingCommand($stock, $data, $actor, $operation));
            $updatedStock = $posting->stock;
            $unit = $this->validatedSerializedUnit($posting, $updatedStock, $operation);

            $this->transitionSerializedUnit($unit, $operation);
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

    private function validatedSerializedUnit(
        InventoryPostingResult $posting,
        InventoryStock $stock,
        MovementType $operation,
    ): ?SerializedInventoryUnit {
        $unit = $posting->serializedUnit;

        if (! $unit instanceof SerializedInventoryUnit) {
            return null;
        }

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
