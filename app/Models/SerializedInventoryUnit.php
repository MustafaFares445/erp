<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SerializedInventoryUnitStatus;
use Database\Factories\SerializedInventoryUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $serial_number
 * @property string|null $iot_number
 */
#[Fillable(['product_variant_id', 'warehouse_id', 'inventory_receipt_item_id', 'serial_number', 'iot_number', 'status'])]
final class SerializedInventoryUnit extends Model
{
    /** @use HasFactory<SerializedInventoryUnitFactory> */
    use HasFactory;

    use SoftDeletes;

    #[\Override]
    public function casts(): array
    {
        return ['status' => SerializedInventoryUnitStatus::class];
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

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
