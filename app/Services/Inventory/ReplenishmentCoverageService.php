<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ReplenishmentCoverageSourceType;
use App\Enums\ReplenishmentCoverageStatus;
use App\Models\ReplenishmentCoverage;
use App\Models\ReplenishmentRequirement;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ReplenishmentCoverageService
{
    public function __construct(private ReplenishmentRequirementService $requirementService) {}

    public function attach(
        ReplenishmentRequirement $requirement,
        ReplenishmentCoverageSourceType $sourceType,
        int $sourceId,
        float $coveredBaseQuantity,
    ): ReplenishmentCoverage {
        if ($sourceId <= 0) {
            throw new DomainException('Replenishment coverage requires a valid source id.');
        }

        if ($coveredBaseQuantity <= 0) {
            throw new DomainException('Replenishment coverage quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($requirement, $sourceType, $sourceId, $coveredBaseQuantity): ReplenishmentCoverage {
            $lockedRequirement = ReplenishmentRequirement::query()
                ->lockForUpdate()
                ->findOrFail($requirement->getKey());

            if ($lockedRequirement->isTerminal()) {
                throw new DomainException('A terminal replenishment requirement cannot receive new coverage.');
            }

            $existing = ReplenishmentCoverage::query()
                ->where('replenishment_requirement_id', $lockedRequirement->getKey())
                ->where('source_type', $sourceType->value)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();

            $otherCoverage = (float) ReplenishmentCoverage::query()
                ->where('replenishment_requirement_id', $lockedRequirement->getKey())
                ->where('status', ReplenishmentCoverageStatus::Active->value)
                ->when(
                    $existing instanceof ReplenishmentCoverage,
                    fn ($query) => $query->whereKeyNot($existing->getKey()),
                )
                ->sum('covered_base_quantity');
            $required = (float) $lockedRequirement->required_base_quantity;

            if ($otherCoverage + $coveredBaseQuantity > $required + 0.000001) {
                throw new DomainException('Replenishment coverage cannot exceed the requirement quantity.');
            }

            $coverage = $existing ?? new ReplenishmentCoverage;
            $coverage->forceFill([
                'replenishment_requirement_id' => $lockedRequirement->getKey(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'covered_base_quantity' => round($coveredBaseQuantity, 6),
                'status' => ReplenishmentCoverageStatus::Active,
            ])->save();

            $this->requirementService->refreshCoverageState($lockedRequirement);

            return $coverage->refresh();
        }, attempts: 5);
    }

    public function release(ReplenishmentCoverage $coverage): ReplenishmentCoverage
    {
        return $this->transition($coverage, ReplenishmentCoverageStatus::Released);
    }

    public function cancel(ReplenishmentCoverage $coverage): ReplenishmentCoverage
    {
        return $this->transition($coverage, ReplenishmentCoverageStatus::Cancelled);
    }

    public function fulfill(ReplenishmentCoverage $coverage): ReplenishmentCoverage
    {
        return $this->transition($coverage, ReplenishmentCoverageStatus::Fulfilled);
    }

    private function transition(
        ReplenishmentCoverage $coverage,
        ReplenishmentCoverageStatus $status,
    ): ReplenishmentCoverage {
        return DB::transaction(function () use ($coverage, $status): ReplenishmentCoverage {
            $locked = ReplenishmentCoverage::query()
                ->lockForUpdate()
                ->findOrFail($coverage->getKey());

            if ($locked->status === $status) {
                return $locked;
            }

            $locked->forceFill(['status' => $status])->save();
            $this->requirementService->refreshCoverageState($locked->requirement);

            return $locked->refresh();
        }, attempts: 5);
    }
}
