<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SupplierProductReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['supplier_id', 'product_variant_id', 'supplier_name', 'supplier_item_number', 'country_code', 'manufacturer', 'purchase_cost', 'currency_code', 'notes', 'is_active'])]
final class SupplierProductReference extends Model
{
    /** @use HasFactory<SupplierProductReferenceFactory> */
    use HasFactory;

    use SoftDeletes;

    #[\Override]
    public function casts(): array
    {
        return ['purchase_cost' => 'decimal:2', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * The single active reference for one supplier and variant, if any.
     *
     * A unique index guarantees there is at most one (V-14), so cost defaulting
     * and cost writeback both have an unambiguous target rather than having to
     * pick between rows.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActiveFor(Builder $query, int $supplierId, int $productVariantId): Builder
    {
        return $query->where('supplier_id', $supplierId)
            ->where('product_variant_id', $productVariantId)
            ->where('is_active', true);
    }
}
