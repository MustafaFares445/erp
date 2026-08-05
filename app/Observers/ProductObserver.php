<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Refuses a {@see ProductType} change once the product has stock history.
 *
 * The product form disables the field for such a product, but a disabled field is a UI
 * affordance rather than a boundary — a tampered request could still submit a new value. This
 * observer is the boundary, matching how {@see PackageObserver} guards a package's warehouse.
 */
final class ProductObserver
{
    public function updating(Product $product): void
    {
        if (! $product->isDirty('product_type')) {
            return;
        }

        if (! $product->hasStockHistory()) {
            return;
        }

        throw ValidationException::withMessages([
            'product_type' => __('admin.inventory.product_type.errors.immutable'),
        ]);
    }
}
