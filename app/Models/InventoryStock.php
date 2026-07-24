<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferStatus;
use Database\Factories\InventoryStockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stock balance for one product variant in one warehouse (ERD §6).
 * Unique per `(product_variant_id, warehouse_id)` — the inventory source of
 * truth (constitution Principle III).
 *
 * READ-ONLY in the Filament dashboard: the inventory stock policy denies
 * every write ability, and the stock-level resource registers no
 * create/edit/delete action (FR-010). Balances are written only by the
 * future adjustment/transfer domain services.
 *
 * No fillable attributes are declared: nothing in this feature creates or
 * updates a row (factories use `forceCreate()`/`newFactory()` state, not
 * mass-assigned `create()` through a Filament form).
 */
final class InventoryStock extends Model
{
    /** @use HasFactory<InventoryStockFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'on_hand_quantity' => 'decimal:3',
            'reserved_quantity' => 'decimal:3',
            'available_quantity' => 'decimal:3',
            'reorder_level' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function isLowStock(): bool
    {
        if ($this->reorder_level === null) {
            return false;
        }

        return (float) $this->available_quantity <= (float) $this->reorder_level;
    }

    public function inTransitQuantity(): float
    {
        return (float) StockTransferItem::query()
            ->where('product_variant_id', $this->product_variant_id)
            ->whereHas('transfer', fn ($query) => $query
                ->where('to_warehouse_id', $this->warehouse_id)
                ->where('status', TransferStatus::Dispatched->value))
            ->sum('quantity');
    }
}
