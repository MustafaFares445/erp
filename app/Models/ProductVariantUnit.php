<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\ProductVariantUnitObserver;
use Database\Factories\ProductVariantUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_variant_id',
    'unit_id',
    'is_base',
    'is_purchase',
    'is_sale',
    'is_display',
    'factor_to_base',
    'rounding_increment',
    'permits_cross_family_conversion',
    'is_active',
    'effective_from',
    'retired_at',
])]
#[ObservedBy(ProductVariantUnitObserver::class)]
final class ProductVariantUnit extends Model
{
    /** @use HasFactory<ProductVariantUnitFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return [
            'is_base' => 'boolean',
            'is_purchase' => 'boolean',
            'is_sale' => 'boolean',
            'is_display' => 'boolean',
            'factor_to_base' => 'decimal:6',
            'rounding_increment' => 'decimal:6',
            'permits_cross_family_conversion' => 'boolean',
            'is_active' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
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
}
