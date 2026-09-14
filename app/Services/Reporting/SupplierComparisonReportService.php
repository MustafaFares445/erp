<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\SupplierProductReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final readonly class SupplierComparisonReportService
{
    /**
     * @param  array<string, bool|int|string>  $filters
     * @return Builder<SupplierProductReference>
     */
    public function query(array $filters): Builder
    {
        $query = SupplierProductReference::query()->with(['supplier', 'productVariant.product']);

        foreach (['supplier_id', 'product_variant_id'] as $key) {
            if (isset($filters[$key]) && is_int($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        foreach (['country_code', 'currency_code'] as $key) {
            if (isset($filters[$key]) && is_string($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        if (isset($filters['is_active']) && is_bool($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query;
    }

    /** @return list<bool|float|int|string|null> */
    public function values(Model $record): array
    {
        if (! $record instanceof SupplierProductReference) {
            throw new LogicException('Supplier comparison reports require supplier product references.');
        }

        return [
            $record->supplier?->name,
            $record->supplier?->code,
            $record->productVariant?->sku,
            $record->productVariant?->name,
            $record->supplier_item_number,
            $record->manufacturer,
            $record->country_code,
            is_numeric($record->purchase_cost) ? (float) $record->purchase_cost : null,
            $record->currency_code,
            $record->is_active,
        ];
    }
}
