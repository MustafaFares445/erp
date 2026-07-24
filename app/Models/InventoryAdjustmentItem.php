<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Inventory\InventoryAdjustmentService;
use Database\Factories\InventoryAdjustmentItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line within an {@see InventoryAdjustment} for one product variant
 * (ERD §6, FI-3). No independent lifecycle — deleted with its parent
 * (`cascadeOnDelete`).
 *
 * `old_quantity` and `difference` are derived/finalized by
 * {@see InventoryAdjustmentService::confirm()} from
 * the live stock balance, never entered by hand — so only
 * `product_variant_id` and `new_quantity` are fillable.
 */
#[Fillable(['product_variant_id', 'serialized_inventory_unit_id', 'new_quantity'])]
final class InventoryAdjustmentItem extends Model
{
    /** @use HasFactory<InventoryAdjustmentItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'old_quantity' => 'decimal:3',
            'new_quantity' => 'decimal:3',
            'difference' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<InventoryAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'inventory_adjustment_id');
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
}
