<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\StockCondition;
use App\Services\Inventory\ReplenishmentRequirementService;
use Database\Factories\InventoryStockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id */
final class InventoryStock extends Model
{
    /** @use HasFactory<InventoryStockFactory> */
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        self::saved(static function (self $stock): void {
            app(ReplenishmentRequirementService::class)->syncForStock($stock);
        });
    }

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'on_hand_quantity' => 'decimal:6',
            'reserved_quantity' => 'decimal:6',
            'damaged_quantity' => 'decimal:6',
            'available_quantity' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function conditionBalance(StockCondition $condition): ?InventoryConditionBalance
    {
        return InventoryConditionBalance::query()
            ->where('product_variant_id', $this->product_variant_id)
            ->where('warehouse_id', $this->warehouse_id)
            ->where('stock_condition', $condition->value)
            ->first();
    }

    public function conditionOnHandQuantity(StockCondition $condition): float
    {
        $balance = $this->conditionBalance($condition);

        if ($balance instanceof InventoryConditionBalance) {
            return (float) $balance->on_hand_base_quantity;
        }

        return match ($condition) {
            StockCondition::Saleable => (float) $this->on_hand_quantity - (float) $this->damaged_quantity,
            StockCondition::Quarantine => 0.0,
            StockCondition::Damaged => (float) $this->damaged_quantity,
            StockCondition::Disposed => 0.0,
        };
    }

    public function conditionReservedQuantity(StockCondition $condition): float
    {
        $balance = $this->conditionBalance($condition);

        if ($balance instanceof InventoryConditionBalance) {
            return (float) $balance->reserved_base_quantity;
        }

        return $condition === StockCondition::Saleable
            ? (float) $this->reserved_quantity
            : 0.0;
    }

    public function saleableAvailableQuantity(): float
    {
        return max(
            0.0,
            $this->conditionOnHandQuantity(StockCondition::Saleable)
                - $this->conditionReservedQuantity(StockCondition::Saleable),
        );
    }

    public function replenishmentPolicy(): ?WarehouseReplenishmentPolicy
    {
        return WarehouseReplenishmentPolicy::query()
            ->where('warehouse_id', $this->warehouse_id)
            ->where('product_variant_id', $this->product_variant_id)
            ->first();
    }

    public function inTransitQuantity(): float
    {
        $loadedQuantity = $this->getAttribute('in_transit_quantity');

        if (is_numeric($loadedQuantity)) {
            return (float) $loadedQuantity;
        }

        $quantity = InventoryOperationLine::query()
            ->where('product_variant_id', $this->product_variant_id)
            ->whereHas('operation', fn (Builder $query): Builder => $query
                ->where('operation_type', OperationType::InternalTransfer->value)
                ->where('destination_warehouse_id', $this->warehouse_id)
                ->whereIn('stage', [OperationStage::InTransit->value, OperationStage::PartiallyReceived->value]))
            ->selectRaw('coalesce(sum(dispatched_base_quantity - received_base_quantity), 0)')
            ->value('coalesce(sum(dispatched_base_quantity - received_base_quantity), 0)');

        return is_numeric($quantity) ? (float) $quantity : 0.0;
    }

    /** @return Builder<InventoryOperationLine> */
    public static function inTransitQuantitySubquery(): Builder
    {
        return InventoryOperationLine::query()
            ->selectRaw('coalesce(sum(inventory_operation_lines.dispatched_base_quantity - inventory_operation_lines.received_base_quantity), 0)')
            ->join('inventory_operations', 'inventory_operations.id', '=', 'inventory_operation_lines.inventory_operation_id')
            ->whereColumn('inventory_operation_lines.product_variant_id', 'inventory_stocks.product_variant_id')
            ->whereColumn('inventory_operations.destination_warehouse_id', 'inventory_stocks.warehouse_id')
            ->where('inventory_operations.operation_type', OperationType::InternalTransfer->value)
            ->whereIn('inventory_operations.stage', [OperationStage::InTransit->value, OperationStage::PartiallyReceived->value]);
    }
}
