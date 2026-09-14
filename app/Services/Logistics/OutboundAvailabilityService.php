<?php

declare(strict_types=1);

namespace App\Services\Logistics;

use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Services\Inventory\InventoryLotService;
use App\Services\Sales\OrderFulfillmentQuantityService;
use Illuminate\Support\Collection;

final readonly class OutboundAvailabilityService
{
    private const float Tolerance = 0.000001;

    public function __construct(
        private OrderFulfillmentQuantityService $quantities,
        private InventoryLotService $lots,
    ) {}

    /**
     * Suggests only stock that is currently available. Uncovered demand stays
     * unplanned and is handed to Procurement as a requirement by the caller.
     *
     * @return list<array<string, mixed>>
     */
    public function suggest(Order $order): array
    {
        $remainingByVariant = [];
        foreach ($this->quantities->forOrder($order) as $progress) {
            if ($progress->remainingToPlanBase > self::Tolerance) {
                $remainingByVariant[$progress->productVariantId] =
                    ($remainingByVariant[$progress->productVariantId] ?? 0.0) + $progress->remainingToPlanBase;
            }
        }

        if ($remainingByVariant === []) {
            return [];
        }

        $variants = ProductVariant::query()
            ->whereIn('id', array_keys($remainingByVariant))
            ->get()
            ->keyBy('id');

        $stocks = InventoryStock::query()
            ->whereIn('product_variant_id', array_keys($remainingByVariant))
            ->where('available_quantity', '>', 0)
            ->whereHas('warehouse', fn ($query) => $query->where('is_active', true))
            ->with('warehouse:id,name')
            ->orderBy('warehouse_id')
            ->orderBy('product_variant_id')
            ->get();

        $shipments = [];

        foreach ($remainingByVariant as $variantId => $demand) {
            $left = $demand;
            $variant = $variants->get($variantId);
            if (! $variant instanceof ProductVariant) {
                continue;
            }

            foreach ($stocks->where('product_variant_id', $variantId) as $stock) {
                if ($left <= self::Tolerance) {
                    break;
                }

                $available = (float) $stock->available_quantity;
                $quantity = min($left, $available);
                if ($quantity <= self::Tolerance) {
                    continue;
                }

                $warehouseId = (int) $stock->warehouse_id;
                $assignments = $this->trackedAssignments($variant, $warehouseId, $quantity);
                $allocated = array_sum(array_column($assignments, 'quantity'));

                if ($allocated <= self::Tolerance) {
                    continue;
                }

                $shipments[$warehouseId] ??= [
                    'warehouse_id' => $warehouseId,
                    'tracking_number' => null,
                    'attachments' => [],
                    'delivery_type' => null,
                    'assignments' => [],
                ];
                array_push($shipments[$warehouseId]['assignments'], ...$assignments);
                $left -= $allocated;
            }
        }

        return array_values($shipments);
    }

    /** @return list<array{product_variant_id:int,quantity:float,inventory_lot_id:?int,serialized_inventory_unit_ids:list<int>}> */
    private function trackedAssignments(ProductVariant $variant, int $warehouseId, float $quantity): array
    {
        if ($variant->track_serials) {
            $count = max(0, (int) floor($quantity + self::Tolerance));
            $serialIds = SerializedInventoryUnit::query()
                ->where('product_variant_id', $variant->id)
                ->where('warehouse_id', $warehouseId)
                ->where('status', SerializedInventoryUnitStatus::Available->value)
                ->orderBy('id')
                ->limit($count)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

            if ($serialIds === []) {
                return [];
            }

            return [[
                'product_variant_id' => $variant->id,
                'quantity' => (float) count($serialIds),
                'inventory_lot_id' => null,
                'serialized_inventory_unit_ids' => $serialIds,
            ]];
        }

        if ($variant->track_batches) {
            $left = $quantity;
            $assignments = [];
            foreach ($this->lots->availableLots($variant->id, $warehouseId) as $lot) {
                if ($left <= self::Tolerance) {
                    break;
                }

                $available = (float) $lot->availableQuantity($warehouseId);
                $allocated = min($left, $available);
                if ($allocated <= self::Tolerance) {
                    continue;
                }

                $assignments[] = [
                    'product_variant_id' => $variant->id,
                    'quantity' => round($allocated, 6),
                    'inventory_lot_id' => (int) $lot->getKey(),
                    'serialized_inventory_unit_ids' => [],
                ];
                $left -= $allocated;
            }

            return $assignments;
        }

        return [[
            'product_variant_id' => $variant->id,
            'quantity' => round($quantity, 6),
            'inventory_lot_id' => null,
            'serialized_inventory_unit_ids' => [],
        ]];
    }
}
