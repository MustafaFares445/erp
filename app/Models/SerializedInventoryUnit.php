<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use Database\Factories\SerializedInventoryUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $serial_number
 * @property string|null $iot_number
 */
#[Fillable(['product_variant_id', 'warehouse_id', 'serial_number', 'iot_number', 'status', 'custody_type', 'custody_reference_type', 'custody_reference_id', 'inventory_lot_id', 'stock_condition'])]
final class SerializedInventoryUnit extends Model
{
    /** @use HasFactory<SerializedInventoryUnitFactory> */
    use HasFactory;

    use SoftDeletes;

    #[\Override]
    public function casts(): array
    {
        return [
            'status' => SerializedInventoryUnitStatus::class,
            'custody_type' => SerializedCustodyType::class,
            'stock_condition' => StockCondition::class,
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

    /** @return BelongsTo<InventoryLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** @return HasOne<InventoryMovement, $this> */
    public function receiptMovement(): HasOne
    {
        return $this->hasOne(InventoryMovement::class)
            ->where('movement_type', MovementType::Receipt->value)
            ->oldestOfMany();
    }
}
