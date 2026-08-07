<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\InventoryStock;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type WarehouseCandidate array{warehouse: Warehouse, stocks: array<int, float>}
 */
final class WarehouseStockService
{
    /**
     * @param  list<int>  $productVariantIds
     * @return array<int, WarehouseCandidate>
     */
    public function eligibleCandidates(array $productVariantIds): array
    {
        $stocks = InventoryStock::query()
            ->select(['id', 'product_variant_id', 'warehouse_id', 'available_quantity'])
            ->with('warehouse:id,name,address,latitude,longitude,is_active')
            ->whereIn('product_variant_id', $productVariantIds)
            ->where('available_quantity', '>', 0)
            ->whereHas('warehouse', fn (Builder $query): Builder => $query
                ->where('is_active', true)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude'))
            ->get();
        $candidates = [];

        foreach ($stocks as $stock) {
            $warehouse = $stock->warehouse;

            if (! $warehouse instanceof Warehouse) {
                continue;
            }

            $warehouseId = (int) $stock->warehouse_id;
            $candidates[$warehouseId] ??= ['warehouse' => $warehouse, 'stocks' => []];
            $candidates[$warehouseId]['stocks'][(int) $stock->product_variant_id] = (float) $stock->available_quantity;
        }

        ksort($candidates);

        return $candidates;
    }

    /**
     * @return array{available_quantity: float, warehouses: list<array{id: int, name: string, available_quantity: float}>}
     */
    public function availability(int $productVariantId): array
    {
        $stocks = InventoryStock::query()
            ->select(['id', 'product_variant_id', 'warehouse_id', 'available_quantity'])
            ->with('warehouse:id,name,is_active')
            ->where('product_variant_id', $productVariantId)
            ->where('available_quantity', '>', 0)
            ->whereHas('warehouse', fn (Builder $query): Builder => $query->where('is_active', true))
            ->get();

        $warehouses = [];

        foreach ($stocks as $stock) {
            $warehouse = $stock->warehouse;

            if (! $warehouse instanceof Warehouse) {
                continue;
            }

            $warehouses[] = [
                'id' => (int) $stock->warehouse_id,
                'name' => $warehouse->name,
                'available_quantity' => (float) $stock->available_quantity,
            ];
        }

        return [
            'available_quantity' => (float) $stocks->sum(fn (InventoryStock $stock): float => (float) $stock->available_quantity),
            'warehouses' => $warehouses,
        ];
    }
}
