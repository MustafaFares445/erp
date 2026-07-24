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
}
