<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductAttributeValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['product_attribute_id', 'value', 'value_ar', 'is_active'])]
final class ProductAttributeValue extends Model
{
    /** @use HasFactory<ProductAttributeValueFactory> */
    use HasFactory;

    use SoftDeletes;

    #[\Override]
    public function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<ProductAttribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }

    /** @return HasMany<ProductVariantAttributeValue, $this> */
    public function variantAssignments(): HasMany
    {
        return $this->hasMany(ProductVariantAttributeValue::class);
    }
}
