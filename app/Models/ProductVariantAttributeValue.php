<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductVariantAttributeValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['product_variant_id', 'product_attribute_value_id'])]
final class ProductVariantAttributeValue extends Model
{
    /** @use HasFactory<ProductVariantAttributeValueFactory> */
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        self::saving(static function (self $assignment): void {
            $attributeId = ProductAttributeValue::query()
                ->whereKey($assignment->product_attribute_value_id)
                ->value('product_attribute_id');
            if (! is_numeric($attributeId)) {
                throw ValidationException::withMessages([
                    'product_attribute_value_id' => __('Select a valid attribute value.'),
                ]);
            }

            if ((int) $assignment->product_variant_id > 0) {
                $duplicate = self::query()
                    ->where('product_variant_id', $assignment->product_variant_id)
                    ->whereKeyNot($assignment->getKey())
                    ->whereHas('attributeValue', static fn ($query) => $query
                        ->where('product_attribute_id', $attributeId))
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'product_attribute_value_id' => __('Each attribute may only be assigned once to a variant.'),
                    ]);
                }
            }
        });
    }

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
