<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\SupplierProductReference;

final readonly class SupplierCostResolver
{
    /** @param numeric-string $variance */
    public function varianceValueMinor(int $productVariantId, string $variance): ?int
    {
        if (bccomp($variance, '0', 6) === 0) {
            return 0;
        }

        $reference = SupplierProductReference::query()
            ->where('product_variant_id', $productVariantId)
            ->where('is_active', true)
            ->whereNotNull('purchase_cost')
            ->orderByDesc('updated_at')
            ->first();

        if (! $reference instanceof SupplierProductReference || $reference->purchase_cost === null) {
            return null;
        }

        $costMinor = (int) round(((float) $reference->purchase_cost) * 100);

        return (int) round(((float) $variance) * $costMinor);
    }
}
