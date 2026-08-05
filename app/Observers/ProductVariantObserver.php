<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Keeps `track_serials`/`track_expiry` a faithful projection of the parent product's
 * {@see ProductType}.
 *
 * The type is the single source of truth, but the flags stay as real columns because every
 * inventory query, index and report already reads them directly. This observer is the
 * backstop, not the mechanism: write surfaces spread {@see ProductType::trackingFlags()}
 * explicitly, because a seeder running under `WithoutModelEvents` never reaches an observer.
 * What this catches is the path that forgets.
 */
final class ProductVariantObserver
{
    public function saving(ProductVariant $variant): void
    {
        $type = $this->productType($variant);

        if (! $type instanceof ProductType) {
            return;
        }

        $variant->forceFill($type->trackingFlags());
    }

    /**
     * Prefers an already-loaded relation so a bulk save does not issue one query per variant,
     * and falls back to a lean lookup keyed on the foreign key being written.
     */
    private function productType(ProductVariant $variant): ?ProductType
    {
        if ($variant->relationLoaded('product')) {
            return $variant->product?->product_type;
        }

        $type = Product::query()->withTrashed()->whereKey($variant->product_id)->value('product_type');

        if ($type instanceof ProductType) {
            return $type;
        }

        return is_string($type) ? ProductType::tryFrom($type) : null;
    }
}
