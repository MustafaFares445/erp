<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InventoryLotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_variant_id', 'warehouse_id', 'inventory_receipt_item_id', 'lot_number', 'expires_at', 'on_hand_quantity', 'reserved_quantity'])]
final class InventoryLot extends Model
{
    /** @use HasFactory<InventoryLotFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return ['expires_at' => 'date', 'on_hand_quantity' => 'decimal:3', 'reserved_quantity' => 'decimal:3'];
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

    /** @return BelongsTo<InventoryReceiptItem, $this> */
    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(InventoryReceiptItem::class, 'inventory_receipt_item_id');
    }

    public function availableQuantity(): float
    {
        return (float) $this->on_hand_quantity - (float) $this->reserved_quantity;
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

        return $daysRemaining <= InventorySetting::current()->expiry_alert_days
            ? 'expiring'
            : 'healthy';
    }
}
