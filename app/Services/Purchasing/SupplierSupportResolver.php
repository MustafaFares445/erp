<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\ProductVariant;
use App\Models\SupplierProductSupport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
        $variantSupplierIds = [];
        $productSupplierIds = [];

        foreach ($supports as $support) {
            if ($support->product_variant_id === $productVariantId) {
                $variantSupplierIds[] = $support->supplier_id;
            }

            if ($support->product_id === $productId) {
                $productSupplierIds[] = $support->supplier_id;
            }
        }

        $variantSupplierIds = array_values(array_unique($variantSupplierIds));

        if ($variantSupplierIds !== []) {
            return $variantSupplierIds;
        }

        return array_values(array_unique($productSupplierIds));
    }
}
