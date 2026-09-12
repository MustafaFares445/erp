<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;

/**
 * Read model for Purchasing. Inventory remains the owner of warehouse facts;
 * this service only projects them for purchase-order review and never stores a
 * destination warehouse on the purchase order.
 */
final readonly class PurchaseOrderWarehouseAvailabilityService
{
    public function __construct(private ReplenishmentProjectionService $projections) {}

    /**
     * @return list<array{
     *     sku: string,
     *     warehouse: string,
     *     on_hand: float,
     *     reserved: float,
     *     saleable_available: float,
     *     in_transit: float,
     *     projected: float
     * }>
     */
    public function rows(PurchaseOrder $order): array
    {
        $order->loadMissing('lines.productVariant');

        $variantIds = $order->lines
            ->map(static fn (PurchaseOrderLine $line): int => (int) $line->product_variant_id)
            ->unique()
            ->values()
            ->all();

        if ($variantIds === []) {
            return [];
        }

        $warehouses = Warehouse::query()->active()->orderBy('name')->get(['id', 'name']);
        $warehouseIds = $warehouses->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $stocks = InventoryStock::query()
            ->whereIn('product_variant_id', $variantIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get()
            ->keyBy(fn (InventoryStock $stock): string => $this->positionKey(
                (int) $stock->product_variant_id,
                (int) $stock->warehouse_id,
            ));

        $policies = WarehouseReplenishmentPolicy::query()
            ->active()
            ->whereIn('product_variant_id', $variantIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get()
            ->keyBy(fn (WarehouseReplenishmentPolicy $policy): string => $this->positionKey(
                (int) $policy->product_variant_id,
                (int) $policy->warehouse_id,
            ));

        $rows = [];

        foreach ($order->lines as $line) {
            $variant = $line->productVariant;

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $variantId = (int) $variant->getKey();

            foreach ($warehouses as $warehouse) {
                $warehouseId = (int) $warehouse->getKey();
                $key = $this->positionKey($variantId, $warehouseId);
                $stock = $stocks->get($key);
                $policy = $policies->get($key);

                $position = $stock instanceof InventoryStock
                    ? $stock
                    : (new InventoryStock)->forceFill([
                        'product_variant_id' => $variantId,
                        'warehouse_id' => $warehouseId,
                        'on_hand_quantity' => 0,
                        'reserved_quantity' => 0,
                        'damaged_quantity' => 0,
                        'available_quantity' => 0,
                    ]);

                $saleable = $position->saleableAvailableQuantity();
                $inTransit = $position->inTransitQuantity();
                $projected = $saleable + $inTransit;

                if ($policy instanceof WarehouseReplenishmentPolicy) {
                    $projection = $this->projections->project($policy);
                    $saleable = $projection->saleableAvailable;
                    $inTransit = $projection->incomingInternalTransfers;
                    $projected = $projection->projectedStock();
                }

                $rows[] = [
                    'sku' => (string) $variant->sku,
                    'warehouse' => (string) $warehouse->name,
                    'on_hand' => (float) $position->on_hand_quantity,
                    'reserved' => (float) $position->reserved_quantity,
                    'saleable_available' => round($saleable, 6),
                    'in_transit' => round($inTransit, 6),
                    'projected' => round($projected, 6),
                ];
            }
        }

        return $rows;
    }

    private function positionKey(int $variantId, int $warehouseId): string
    {
        return $variantId.':'.$warehouseId;
    }
}
