<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_variant_id',
    'warehouse_id',
    'quantity_base',
    'average_unit_cost',
    'inventory_value',
])]
final class InventoryValuationBalance extends Model
{
    #[\Override]
    protected function casts(): array
    {
        return [
            'quantity_base' => 'decimal:6',
            'average_unit_cost' => 'decimal:6',
            'inventory_value' => 'decimal:2',
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
}
