<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InventoryReceiptItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property numeric-string $quantity */
#[Fillable(['product_variant_id', 'unit_id', 'warehouse_location_id', 'quantity', 'purchase_cost', 'currency_code', 'expires_at', 'lot_number'])]
final class InventoryReceiptItem extends Model
{
    /** @use HasFactory<InventoryReceiptItemFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return ['quantity' => 'decimal:3', 'purchase_cost' => 'decimal:2', 'expires_at' => 'date'];
    }

    /** @return BelongsTo<InventoryReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(InventoryReceipt::class, 'inventory_receipt_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<SerializedInventoryUnit, $this> */
    public function serializedUnits(): HasMany
    {
        return $this->hasMany(SerializedInventoryUnit::class);
    }

    /** @return BelongsTo<WarehouseLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }
}
