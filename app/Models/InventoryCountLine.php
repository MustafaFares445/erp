<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockCondition;
use App\Services\Inventory\InventoryCountService;
use Database\Factories\InventoryCountLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One counted grain (variant x lot x serial x condition) within an
 * {@see InventoryCount} (GAP-MW-06).
 *
 * `counted_base_quantity` is nullable and that nullability is load-bearing:
 * `null` means the grain has not been counted yet; `'0.000000'` means it was
 * counted and found empty. Nothing here — or in
 * {@see InventoryCountService} — may coerce one into the other.
 * `variance_base_quantity` and `variance_value_minor` are always derived by
 * the service from `counted_base_quantity - system_base_quantity`, never
 * entered directly, so they are not fillable.
 */
#[Fillable(['note'])]
final class InventoryCountLine extends Model
{
    /** @use HasFactory<InventoryCountLineFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'stock_condition' => StockCondition::class,
            'system_base_quantity' => 'decimal:6',
            'counted_base_quantity' => 'decimal:6',
            'variance_base_quantity' => 'decimal:6',
            'recount_requested' => 'boolean',
        ];
    }

    /** @return BelongsTo<InventoryCount, $this> */
    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<InventoryLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function serializedUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class, 'serialized_inventory_unit_id');
    }

    public function isCounted(): bool
    {
        return $this->counted_base_quantity !== null;
    }
}
