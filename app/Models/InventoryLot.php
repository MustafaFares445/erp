<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockCondition;
use Database\Factories\InventoryLotFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stable physical lot identity.
 *
 * Warehouse and quantity are deliberately not part of the canonical identity.
 * Current quantity lives only in InventoryLotBalance at (lot, warehouse, condition).
 *
 * @property int $id
 * @property int $product_variant_id
 * @property string|null $lot_number
 * @property string|null $normalized_lot_number
 */
#[Fillable([
    'product_variant_id',
    'inventory_receipt_item_id',
    'lot_number',
    'normalized_lot_number',
    'expires_at',
    'origin_source_type',
    'origin_source_id',
    'origin_source_line_id',
])]
final class InventoryLot extends Model
{
    /** @use HasFactory<InventoryLotFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return ['expires_at' => 'date'];
    }

    #[\Override]
    protected static function booted(): void
    {
        self::creating(function (self $lot): void {
            $lot->normalized_lot_number ??= self::normalizeLotNumber($lot->lot_number);
        });

        self::updating(function (self $lot): void {
            if ($lot->isDirty('lot_number')) {
                $lot->normalized_lot_number = self::normalizeLotNumber($lot->lot_number);
            }

            if (! $lot->isDirty([
                'product_variant_id',
                'lot_number',
                'normalized_lot_number',
                'expires_at',
            ])) {
                return;
            }

            $hasHistory = $lot->conditionBalances()->exists()
                || $lot->movements()->exists()
                || $lot->reservationAllocations()->exists();

            if ($hasHistory) {
                throw new DomainException(
                    'A lot identity and expiry are immutable after inventory history exists.',
                );
            }
        });
    }

    /** @return Builder<self> */
    public function scopeCanonical(Builder $query): Builder
    {
        return $query->whereNull('canonical_inventory_lot_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return HasMany<InventoryLotBalance, $this> */
    public function conditionBalances(): HasMany
    {
        return $this->hasMany(InventoryLotBalance::class, 'inventory_lot_id');
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'inventory_lot_id');
    }

    /** @return HasMany<InventoryReservationAllocation, $this> */
    public function reservationAllocations(): HasMany
    {
        return $this->hasMany(InventoryReservationAllocation::class, 'inventory_lot_id');
    }

    /** @return BelongsTo<self, $this> */
    public function canonicalLot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_inventory_lot_id');
    }

    /**
     * Legacy receipt provenance only. New canonical receipts use origin_source_*.
     *
     * @return BelongsTo<InventoryReceiptItem, $this>
     */
    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(InventoryReceiptItem::class, 'inventory_receipt_item_id');
    }

    public function conditionBalance(
        StockCondition $condition,
        int $warehouseId,
    ): ?InventoryLotBalance {
        return $this->conditionBalances()
            ->where('warehouse_id', $warehouseId)
            ->where('stock_condition', $condition->value)
            ->first();
    }

    public function conditionOnHandQuantity(
        StockCondition $condition,
        int $warehouseId,
    ): float {
        return (float) ($this->conditionBalance($condition, $warehouseId)?->on_hand_base_quantity ?? 0);
    }

    public function conditionReservedQuantity(
        StockCondition $condition,
        int $warehouseId,
    ): float {
        return (float) ($this->conditionBalance($condition, $warehouseId)?->reserved_base_quantity ?? 0);
    }

    public function availableQuantity(int $warehouseId): float
    {
        return max(
            0.0,
            $this->conditionOnHandQuantity(StockCondition::Saleable, $warehouseId)
                - $this->conditionReservedQuantity(StockCondition::Saleable, $warehouseId),
        );
    }

    public function totalPhysicalQuantity(): float
    {
        return (float) $this->conditionBalances()->sum('on_hand_base_quantity');
    }

    public function totalAvailableQuantity(): float
    {
        return (float) $this->conditionBalances()
            ->where('stock_condition', StockCondition::Saleable->value)
            ->get()
            ->sum(fn (InventoryLotBalance $balance): float => max(
                0.0,
                (float) $balance->on_hand_base_quantity - (float) $balance->reserved_base_quantity,
            ));
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

    public static function normalizeLotNumber(?string $lotNumber): ?string
    {
        if ($lotNumber === null) {
            return null;
        }

        $trimmed = trim($lotNumber);

        if ($trimmed === '') {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;

        return mb_strtoupper($collapsed, 'UTF-8');
    }
}
