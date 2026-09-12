<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'inventory_movement_id',
    'product_variant_id',
    'warehouse_id',
    'valuation_date',
    'valuation_method',
    'base_quantity_delta',
    'unit_cost_snapshot',
    'inventory_value_delta',
])]
final class InventoryValuationEntry extends Model
{
    #[\Override]
    protected function casts(): array
    {
        return [
            'valuation_date' => 'date',
            'base_quantity_delta' => 'decimal:6',
            'unit_cost_snapshot' => 'decimal:6',
            'inventory_value_delta' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<InventoryMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
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
}
