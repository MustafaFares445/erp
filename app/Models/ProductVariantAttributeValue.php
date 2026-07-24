<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductVariantAttributeValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_variant_id', 'product_attribute_value_id'])]
final class ProductVariantAttributeValue extends Model
{
    /** @use HasFactory<ProductVariantAttributeValueFactory> */
    use HasFactory;

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<ProductAttributeValue, $this> */
    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(ProductAttributeValue::class, 'product_attribute_value_id');
    }
}
