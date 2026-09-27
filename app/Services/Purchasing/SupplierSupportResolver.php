<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\ProductVariant;
use App\Models\SupplierProductSupport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves supplier capability only.
 *
 * Capability resolution is deliberately not a price lookup: product-wide and
 * variant-specific support are both positive capability facts. Commercial
 * eligibility for a Purchase Order still requires an active
 * SupplierProductReference.
 */
final readonly class SupplierSupportResolver
{
    /**
     * @param  list<int>  $productVariantIds
     * @return list<int>
     */
    public function eligibleSupplierIds(array $productVariantIds): array
    {
        $productVariantIds = array_values(array_unique($productVariantIds));

        if ($productVariantIds === []) {
            return [];
        }

        $variants = ProductVariant::query()
            ->whereIn('id', $productVariantIds)
            ->get(['id', 'product_id']);

        if ($variants->count() !== count($productVariantIds)) {
            return [];
        }

        $supports = SupplierProductSupport::query()
            ->where('is_active', true)
            ->whereHas('supplier', static fn (Builder $query): Builder => $query->where('is_active', true))
            ->where(function (Builder $query) use ($productVariantIds, $variants): void {
                $query->whereIn('product_variant_id', $productVariantIds)
                    ->orWhereIn('product_id', $variants->pluck('product_id'));
            })
            ->get(['supplier_id', 'product_id', 'product_variant_id']);

        $eligibleSupplierIds = null;

        foreach ($variants as $variant) {
            $supplierIds = $this->supplierIdsForVariant($supports, (int) $variant->id, (int) $variant->product_id);

            if ($supplierIds === []) {
                return [];
            }

            $eligibleSupplierIds = $eligibleSupplierIds === null
                ? $supplierIds
                : array_values(array_intersect($eligibleSupplierIds, $supplierIds));

            if ($eligibleSupplierIds === []) {
                return [];
            }
        }

        return $eligibleSupplierIds ?? [];
    }

    /**
     * @param  Collection<int, SupplierProductSupport>  $supports
     * @return list<int>
     */
    private function supplierIdsForVariant(Collection $supports, int $productVariantId, int $productId): array
    {
        $supplierIds = [];

        foreach ($supports as $support) {
            if ($support->product_variant_id === $productVariantId || $support->product_id === $productId) {
                $supplierIds[] = $support->supplier_id;
            }
        }

        return array_values(array_unique($supplierIds));
    }
}
