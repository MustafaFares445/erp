<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockCondition;
use Database\Factories\InventoryLotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $product_variant_id
 * @property int $warehouse_id
 * @property string|null $lot_number
 * @property numeric-string $on_hand_quantity
 * @property numeric-string $reserved_quantity
 */
#[Fillable(['product_variant_id', 'warehouse_id', 'inventory_receipt_item_id', 'lot_number', 'expires_at', 'on_hand_quantity', 'reserved_quantity'])]
final class InventoryLot extends Model
{
    /** @use HasFactory<InventoryLotFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return ['expires_at' => 'date', 'on_hand_quantity' => 'decimal:6', 'reserved_quantity' => 'decimal:6'];
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

    /** @return HasMany<InventoryLotBalance, $this> */
    public function conditionBalances(): HasMany
    {
        return $this->hasMany(InventoryLotBalance::class, 'inventory_lot_id');
    }

    /** @return BelongsTo<InventoryReceiptItem, $this> */
    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(InventoryReceiptItem::class, 'inventory_receipt_item_id');
    }

    public function conditionBalance(StockCondition $condition): ?InventoryLotBalance
    {
        return $this->conditionBalances()
            ->where('stock_condition', $condition->value)
            ->first();
    }

    public function conditionOnHandQuantity(StockCondition $condition): float
    {
        $balance = $this->conditionBalance($condition);

        if ($balance instanceof InventoryLotBalance) {
            return (float) $balance->on_hand_base_quantity;
        }

        return $condition === StockCondition::Saleable
            ? (float) $this->on_hand_quantity
            : 0.0;
    }

    public function conditionReservedQuantity(StockCondition $condition): float
    {
        $balance = $this->conditionBalance($condition);

        if ($balance instanceof InventoryLotBalance) {
            return (float) $balance->reserved_base_quantity;
        }

        return $condition === StockCondition::Saleable
            ? (float) $this->reserved_quantity
            : 0.0;
    }

    public function availableQuantity(): float
    {
        return max(
            0.0,
            $this->conditionOnHandQuantity(StockCondition::Saleable)
                - $this->conditionReservedQuantity(StockCondition::Saleable),
        );
    }

    public function daysRemaining(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) today()->diffInDays($this->expires_at->copy()->startOfDay(), false);
    }

    public function expiryState(): string
    {
        $daysRemaining = $this->daysRemaining();

        if ($daysRemaining === null) {
            return 'no_expiry';
        }

        if ($daysRemaining < 0) {
            return 'expired';
        }

        return $daysRemaining <= InventorySetting::expiryAlertDays()
            ? 'expiring'
            : 'healthy';
    }
}
