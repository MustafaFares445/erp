<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\ReplenishmentTransferSuggestion;
use App\Models\InventoryStock;
use App\Models\ReplenishmentRequirement;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Support\Collection;

final readonly class ReplenishmentTransferSuggestionService
{
    public function __construct(private ReplenishmentProjectionService $projectionService) {}

    /** @return list<ReplenishmentTransferSuggestion> */
    public function suggest(ReplenishmentRequirement $requirement): array
    {
        $remaining = $requirement->remainingUncoveredQuantity();

        if ($remaining <= 0) {
            return [];
        }

        $candidates = WarehouseReplenishmentPolicy::query()
            ->active()
            ->where('product_variant_id', $requirement->product_variant_id)
            ->where('warehouse_id', '!=', $requirement->warehouse_id)
            ->with('warehouse:id,name')
            ->get()
            ->map(function (WarehouseReplenishmentPolicy $policy): array {
                $available = $this->projectionService->saleableAvailable($policy);

                return $this->candidate($policy, $available);
            });

        return $this->suggestFromCandidates($requirement, $candidates);
    }

    /**
     * Batch version for dashboards: policies, warehouses, stocks and condition
     * balances are loaded once for the full requirement set.
     *
     * @param  Collection<int, ReplenishmentRequirement>  $requirements
     * @return array<int, list<ReplenishmentTransferSuggestion>>
     */
    public function suggestMany(Collection $requirements): array
    {
        $active = $requirements
            ->filter(static fn (ReplenishmentRequirement $requirement): bool => $requirement->remainingUncoveredQuantity() > 0)
            ->values();

        if ($active->isEmpty()) {
            return [];
        }

        $variantIds = $active->pluck('product_variant_id')->unique()->values();
        $policies = WarehouseReplenishmentPolicy::query()
            ->active()
            ->whereIn('product_variant_id', $variantIds)
            ->with('warehouse:id,name')
            ->get();

        $warehouseIds = $policies->pluck('warehouse_id')->unique()->values();
        $stocks = InventoryStock::query()
            ->whereIn('product_variant_id', $variantIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->with('conditionBalances')
            ->get()
            ->keyBy(static fn (InventoryStock $stock): string => $stock->product_variant_id.':'.$stock->warehouse_id);

        $result = [];

        foreach ($active as $requirement) {
            $id = $requirement->getKey();

            if (! is_numeric($id)) {
                continue;
            }

            $candidates = $policies
                ->where('product_variant_id', $requirement->product_variant_id)
                ->reject(static fn (WarehouseReplenishmentPolicy $policy): bool => (int) $policy->warehouse_id === (int) $requirement->warehouse_id)
                ->map(function (WarehouseReplenishmentPolicy $policy) use ($stocks): array {
                    $stock = $stocks->get($policy->product_variant_id.':'.$policy->warehouse_id);
                    $available = $stock instanceof InventoryStock ? $stock->saleableAvailableQuantity() : 0.0;

                    return $this->candidate($policy, $available);
                });

            $result[(int) $id] = $this->suggestFromCandidates($requirement, $candidates);
        }

        return $result;
    }

    /** @return array{policy: WarehouseReplenishmentPolicy, available: float, surplus: float} */
    private function candidate(WarehouseReplenishmentPolicy $policy, float $available): array
    {
        return [
            'policy' => $policy,
            'available' => $available,
            'surplus' => max(0.0, round($available - (float) $policy->max_quantity, 6)),
        ];
    }

    /**
     * @param  Collection<int, array{policy: WarehouseReplenishmentPolicy, available: float, surplus: float}>  $candidates
     * @return list<ReplenishmentTransferSuggestion>
     */
    private function suggestFromCandidates(ReplenishmentRequirement $requirement, Collection $candidates): array
    {
        $remaining = $requirement->remainingUncoveredQuantity();
        $suggestions = [];

        foreach ($candidates
            ->filter(static fn (array $candidate): bool => $candidate['surplus'] > 0)
            ->sortByDesc('surplus') as $candidate) {
            if ($remaining <= 0) {
                break;
            }

            $policy = $candidate['policy'];
            $warehouse = $policy->warehouse;

            if (! $warehouse instanceof Warehouse) {
                continue;
            }

            $suggested = min($remaining, $candidate['surplus']);
            $suggestions[] = new ReplenishmentTransferSuggestion(
                sourceWarehouseId: (int) $policy->warehouse_id,
                sourceWarehouseName: $warehouse->name,
                saleableAvailable: $candidate['available'],
                sourceMinimum: (float) $policy->min_quantity,
                sourceMaximum: (float) $policy->max_quantity,
                suggestedBaseQuantity: round($suggested, 6),
            );
            $remaining = round($remaining - $suggested, 6);
        }

        return $suggestions;
    }
}
