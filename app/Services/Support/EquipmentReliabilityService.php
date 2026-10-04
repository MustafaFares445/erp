<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\EquipmentReliabilityMetrics;
use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Enums\TicketStatus;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\SerializedInventoryUnit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class EquipmentReliabilityService
{
    public function __construct(private MaintenanceCostService $costService) {}

    public function metrics(SerializedInventoryUnit $unit): EquipmentReliabilityMetrics
    {
        /** @var Collection<int, MaintenanceRecord> $jobs */
        $jobs = MaintenanceRecord::query()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->with(['serviceRecords.parts', 'labourEntries', 'thirdPartyCosts', 'warrantyRecoveryClaim'])
            ->orderBy('created_at')
            ->get();

        $corrective = $jobs
            ->filter(static fn (MaintenanceRecord $job): bool => $job->maintenance_kind === MaintenanceKind::Corrective)
            ->values();

        $totalCost = 0;
        $coveredCost = 0;
        $claimed = 0;
        $received = 0;
        $outstanding = 0;

        foreach ($jobs as $job) {
            $cost = $this->costService->jobCost($job);
            $totalCost += $cost['total_cost_minor'];

            if (in_array($job->coverage_decision->value, [
                'fully_covered',
                'goodwill',
                'third_party_warranty',
                'service_contract',
            ], true)) {
                $coveredCost += $cost['total_cost_minor'];
            }

            $claim = $job->warrantyRecoveryClaim;
            if ($claim !== null) {
                $claimed += (int) $claim->claimed_amount_minor;
                $received += (int) $claim->received_amount_minor;
                $outstanding += $claim->outstandingMinor();
            }
        }

        $durations = [];
        foreach ($corrective as $job) {
            $failureStartedAt = $job->failure_started_at;
            $serviceRestoredAt = $job->service_restored_at;

            if ($failureStartedAt !== null && $serviceRestoredAt !== null) {
                $durations[] = max(0.0, (float) $failureStartedAt->diffInMinutes($serviceRestoredAt));
            }
        }

        $failureStarts = $corrective
            ->filter(static fn (MaintenanceRecord $job): bool => $job->failure_started_at !== null)
            ->sortBy('failure_started_at')
            ->values();

        $betweenFailures = [];
        $previous = null;
        foreach ($failureStarts as $current) {
            $currentStartedAt = $current->failure_started_at;
            $previousRestoredAt = $previous?->service_restored_at;

            if ($previousRestoredAt !== null && $currentStartedAt !== null && $currentStartedAt->gt($previousRestoredAt)) {
                $betweenFailures[] = (float) $previousRestoredAt->diffInHours($currentStartedAt);
            }

            $previous = $current;
        }

        $repeatFailures = [];
        foreach ($corrective as $job) {
            if ($job->failure_category !== null) {
                $category = $job->failure_category->value;
                $repeatFailures[$category] = ($repeatFailures[$category] ?? 0) + 1;
            }
        }
        $repeatFailures = array_filter($repeatFailures, static fn (int $count): bool => $count > 1);

        $lastService = $this->lastServiceAt($jobs);

        $nextPreventive = MaintenanceSchedule::query()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->where('is_active', true)
            ->whereNotNull('next_due_on')
            ->min('next_due_on');

        return new EquipmentReliabilityMetrics(
            activeTickets: $unit->tickets()
                ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value, TicketStatus::Cancelled->value])
                ->count(),
            activeMaintenanceJobs: $jobs
                ->reject(static fn (MaintenanceRecord $job): bool => in_array($job->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true))
                ->count(),
            lastServiceAt: $lastService?->toDateTimeString(),
            nextPreventiveDueOn: is_string($nextPreventive) ? $nextPreventive : null,
            lifetimeServiceCostMinor: $totalCost,
            warrantyCoveredCostMinor: $coveredCost,
            recoveryClaimedMinor: $claimed,
            recoveryReceivedMinor: $received,
            recoveryOutstandingMinor: $outstanding,
            correctiveFailureCount: $corrective->count(),
            repeatFailureCategories: $repeatFailures,
            mttrMinutes: $durations !== [] ? round(array_sum($durations) / count($durations), 2) : null,
            mtbfHours: $betweenFailures !== [] ? round(array_sum($betweenFailures) / count($betweenFailures), 2) : null,
        );
    }

    /**
     * Latest restoration time of a closed job, falling back to the most recent update across all jobs.
     *
     * @param  Collection<int, MaintenanceRecord>  $jobs
     */
    private function lastServiceAt(Collection $jobs): ?CarbonInterface
    {
        $latestRestoration = null;
        $latestUpdate = null;

        foreach ($jobs as $job) {
            $restoredAt = $job->service_restored_at;

            if ($job->status === MaintenanceStatus::Closed
                && $restoredAt !== null
                && ($latestRestoration === null || $restoredAt->gt($latestRestoration))) {
                $latestRestoration = $restoredAt;
            }

            $updatedAt = $job->updated_at;

            if ($updatedAt !== null && ($latestUpdate === null || $updatedAt->gt($latestUpdate))) {
                $latestUpdate = $updatedAt;
            }
        }

        return $latestRestoration ?? $latestUpdate;
    }
}
