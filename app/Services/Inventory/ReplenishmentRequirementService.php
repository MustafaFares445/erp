<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ReplenishmentCoverageStatus;
use App\Enums\ReplenishmentRequirementStatus;
use App\Models\InventoryStock;
use App\Models\ReplenishmentRequirement;
use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Support\Facades\DB;

final readonly class ReplenishmentRequirementService
{
    public function __construct(private ReplenishmentProjectionService $projectionService) {}

    public function syncForStock(InventoryStock $stock): ?ReplenishmentRequirement
    {
        $policy = WarehouseReplenishmentPolicy::query()
            ->where('warehouse_id', $stock->warehouse_id)
            ->where('product_variant_id', $stock->product_variant_id)
            ->first();

        if (! $policy instanceof WarehouseReplenishmentPolicy) {
            $this->cancelActiveRequirementsFor($stock->warehouse_id, $stock->product_variant_id);

            return null;
        }

        return $this->sync($policy);
    }

    public function sync(WarehouseReplenishmentPolicy $policy): ?ReplenishmentRequirement
    {
        return DB::transaction(function () use ($policy): ?ReplenishmentRequirement {
            $lockedPolicy = WarehouseReplenishmentPolicy::query()
                ->lockForUpdate()
                ->findOrFail($policy->getKey());
            $requirement = $this->activeRequirement($lockedPolicy, true);

            if (! $lockedPolicy->is_active) {
                return $requirement instanceof ReplenishmentRequirement
                    ? $this->cancel($requirement)
                    : null;
            }

            $projection = $this->projectionService->project($lockedPolicy);
            $projectedStock = $projection->projectedStock();
            $minimum = (float) $lockedPolicy->min_quantity;
            $maximum = (float) $lockedPolicy->max_quantity;

            if (! $requirement instanceof ReplenishmentRequirement) {
                if ($projectedStock > $minimum) {
                    return null;
                }

                return ReplenishmentRequirement::query()->create([
                    'warehouse_replenishment_policy_id' => $lockedPolicy->getKey(),
                    'warehouse_id' => $lockedPolicy->warehouse_id,
                    'product_variant_id' => $lockedPolicy->product_variant_id,
                    'required_base_quantity' => round(max(0.0, $maximum - $projectedStock), 6),
                    'covered_base_quantity' => 0,
                    'fulfilled_base_quantity' => 0,
                    'status' => ReplenishmentRequirementStatus::Open,
                    'triggered_at' => now(),
                ]);
            }

            if ($projection->saleableAvailable >= $maximum) {
                $requirement->forceFill([
                    'fulfilled_base_quantity' => $requirement->required_base_quantity,
                    'status' => ReplenishmentRequirementStatus::Fulfilled,
                    'resolved_at' => now(),
                ])->save();

                return $requirement->refresh();
            }

            $coverage = $this->activeCoverageQuantity($requirement);
            $currentUncoveredNeed = max(0.0, $maximum - $projectedStock);
            $expandedRequired = max(
                (float) $requirement->required_base_quantity,
                round($coverage + $currentUncoveredNeed, 6),
            );

            if (
                $projection->saleableAvailable > $minimum
                && $projection->totalConfirmedIncoming() <= 0
                && $coverage <= 0
            ) {
                return $this->cancel($requirement);
            }

            $requirement->forceFill([
                'required_base_quantity' => $expandedRequired,
                'covered_base_quantity' => min($expandedRequired, $coverage),
                'status' => $this->coverageStatus($expandedRequired, $coverage),
                'resolved_at' => null,
            ])->save();

            return $requirement->refresh();
        }, attempts: 5);
    }

    public function refreshCoverageState(ReplenishmentRequirement $requirement): ReplenishmentRequirement
    {
        return DB::transaction(function () use ($requirement): ReplenishmentRequirement {
            $locked = ReplenishmentRequirement::query()
                ->lockForUpdate()
                ->findOrFail($requirement->getKey());

            if ($locked->isTerminal()) {
                return $locked;
            }

            $required = (float) $locked->required_base_quantity;
            $coverage = $this->activeCoverageQuantity($locked);

            $locked->forceFill([
                'covered_base_quantity' => min($required, $coverage),
                'status' => $this->coverageStatus($required, $coverage),
                'resolved_at' => null,
            ])->save();

            return $locked->refresh();
        }, attempts: 5);
    }

    private function activeRequirement(
        WarehouseReplenishmentPolicy $policy,
        bool $lock = false,
    ): ?ReplenishmentRequirement {
        $query = ReplenishmentRequirement::query()
            ->where('warehouse_replenishment_policy_id', $policy->getKey())
            ->active()
            ->oldest('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function activeCoverageQuantity(ReplenishmentRequirement $requirement): float
    {
        return round((float) $requirement->coverages()
            ->where('status', ReplenishmentCoverageStatus::Active->value)
            ->sum('covered_base_quantity'), 6);
    }

    private function coverageStatus(float $required, float $covered): ReplenishmentRequirementStatus
    {
        if ($covered <= 0) {
            return ReplenishmentRequirementStatus::Open;
        }

        if ($covered + 0.000001 < $required) {
            return ReplenishmentRequirementStatus::PartiallyCovered;
        }

        return ReplenishmentRequirementStatus::Covered;
    }

    private function cancel(ReplenishmentRequirement $requirement): ReplenishmentRequirement
    {
        $requirement->forceFill([
            'status' => ReplenishmentRequirementStatus::Cancelled,
            'resolved_at' => now(),
        ])->save();

        return $requirement->refresh();
    }

    private function cancelActiveRequirementsFor(int $warehouseId, int $productVariantId): void
    {
        ReplenishmentRequirement::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $productVariantId)
            ->active()
            ->update([
                'status' => ReplenishmentRequirementStatus::Cancelled->value,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
