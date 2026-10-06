<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ProductOperationalProfile;
use App\Enums\ProductType;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Protects catalog identity once stock history exists and keeps manufacturer/brand
 * references coherent without rewriting legacy product-type behaviour.
 */
final class ProductObserver
{
    public function saving(Product $product): void
    {
        if ($product->operational_profile === null) {
            $type = $product->product_type;

            if ($type instanceof ProductType) {
                $product->operational_profile = ProductOperationalProfile::fromLegacyType($type);
            }
        }

        if ($product->brand_id === null) {
            return;
        }

        $brand = Brand::query()->withTrashed()->find($product->brand_id);

        if (! $brand instanceof Brand || $brand->manufacturer_id === null) {
            return;
        }

        if ($product->manufacturer_id === null) {
            $product->manufacturer_id = $brand->manufacturer_id;

            return;
        }

        if ((int) $product->manufacturer_id !== (int) $brand->manufacturer_id) {
            throw ValidationException::withMessages([
                'brand_id' => __('The selected brand does not belong to the selected manufacturer.'),
            ]);
        }
    }

    public function updating(Product $product): void
    {
        if (! $product->isDirty(['product_type', 'operational_profile'])) {
            return;
        }

        if (! $product->hasStockHistory()) {
            return;
        }

        $field = $product->isDirty('product_type') ? 'product_type' : 'operational_profile';

        throw ValidationException::withMessages([
            $field => __('Product inventory behaviour cannot change after stock history exists.'),
        ]);
    }
}
