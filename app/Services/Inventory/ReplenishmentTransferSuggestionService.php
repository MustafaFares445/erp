<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\ReplenishmentTransferSuggestion;
use App\Models\ReplenishmentRequirement;
use App\Models\WarehouseReplenishmentPolicy;

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
                $maximum = (float) $policy->max_quantity;

                return [
                    'policy' => $policy,
                    'available' => $available,
                    'surplus' => max(0.0, round($available - $maximum, 6)),
                ];
            })
            ->filter(static fn (array $candidate): bool => $candidate['surplus'] > 0)
            ->sortByDesc('surplus')
            ->values();

        $suggestions = [];

        foreach ($candidates as $candidate) {
            if ($remaining <= 0) {
                break;
            }

            /** @var WarehouseReplenishmentPolicy $policy */
            $policy = $candidate['policy'];
            $suggested = min($remaining, (float) $candidate['surplus']);

            if ($suggested <= 0) {
                continue;
            }

            $suggestions[] = new ReplenishmentTransferSuggestion(
                sourceWarehouseId: (int) $policy->warehouse_id,
                sourceWarehouseName: (string) ($policy->warehouse?->name ?? ''),
                saleableAvailable: (float) $candidate['available'],
                sourceMinimum: (float) $policy->min_quantity,
                sourceMaximum: (float) $policy->max_quantity,
                suggestedBaseQuantity: round($suggested, 6),
            );
            $remaining = round($remaining - $suggested, 6);
        }

        return $suggestions;
    }
}
