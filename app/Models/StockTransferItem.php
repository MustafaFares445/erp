<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StockTransferItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line within a {@see StockTransfer} for one product variant moving from
 * the transfer's source to its destination (ERD §6, FI-4). No independent
 * lifecycle — deleted with its parent (`cascadeOnDelete`).
 *
 * Duplicate lines for the same variant within one transfer are permitted
 * (research D4): the source-availability check sums them, but each line
 * still produces its own paired movement on confirm — so lines are never
 * merged here.
 */
#[Fillable(['product_variant_id', 'serialized_inventory_unit_id', 'warehouse_location_id', 'quantity'])]
final class StockTransferItem extends Model
{
    /** @use HasFactory<StockTransferItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function serializedUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class, 'serialized_inventory_unit_id');
    }

    /**
     * The destination warehouse's put-away location for this line, assigned
     * at receive time. Optional — a transfer may be received without one.
     *
     * @return BelongsTo<WarehouseLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }
}
