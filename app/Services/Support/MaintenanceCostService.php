<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\LabourEntryData;
use App\Data\Support\ThirdPartyCostData;
use App\Enums\CostSource;
use App\Models\MaintenanceLabourEntry;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\ServiceRecordPart;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Read-only job-cost and margin figures for a {@see MaintenanceRecord}
 * (WP-2.9, GAP-MW-09) — a management figure derived purely from the parts,
 * labour, and third-party cost snapshots already recorded elsewhere in this
 * module. Never posts to the ledger and never corrects, plugs, or hides a
 * snapshot: an unknown part cost stays unknown here, surfaced through
 * `coverage_percent` rather than defaulted to zero.
 */
final readonly class MaintenanceCostService
{
    /**
     * @return array{
     *     parts_cost_minor: int,
     *     labour_cost_minor: int,
     *     third_party_cost_minor: int,
     *     total_cost_minor: int,
     *     coverage_percent: float,
     * }
     */
    public function jobCost(MaintenanceRecord $record): array
    {
        $parts = $this->activeParts($record);

        $partsCostMinor = (int) $parts->sum(fn (ServiceRecordPart $part): int => $part->total_cost_minor ?? 0);
        $knownParts = $parts->filter(fn (ServiceRecordPart $part): bool => $part->cost_source !== null && $part->cost_source !== CostSource::Unknown);
        $coveragePercent = $parts->isEmpty() ? 100.0 : round($knownParts->count() / $parts->count() * 100, 2);

        $labourCostMinor = (int) $record->labourEntries()->sum('total_cost_minor');
        $thirdPartyCostMinor = (int) $record->thirdPartyCosts()->sum('amount_minor');

        return [
            'parts_cost_minor' => $partsCostMinor,
            'labour_cost_minor' => $labourCostMinor,
            'third_party_cost_minor' => $thirdPartyCostMinor,
            'total_cost_minor' => $partsCostMinor + $labourCostMinor + $thirdPartyCostMinor,
            'coverage_percent' => $coveragePercent,
        ];
    }

    /**
     * Cost against revenue for this job (GAP-MW-09/F-07) — revenue is nil for
     * anything but an `Invoiced` job, so warranty-covered and ticket-settled
     * work reports its real cost against zero revenue rather than a blank
     * record.
     *
     * @return array{cost_minor: int, revenue_minor: int, margin_minor: int, billing_type: string}
     */
    public function marginFor(MaintenanceRecord $record): array
    {
        $cost = $this->jobCost($record);
        $revenueRaw = $record->invoice()->value('total_amount');
        $revenueMinor = is_numeric($revenueRaw) ? (int) round(((float) $revenueRaw) * 100) : 0;

        return [
            'cost_minor' => $cost['total_cost_minor'],
            'revenue_minor' => $revenueMinor,
            'margin_minor' => $revenueMinor - $cost['total_cost_minor'],
            'billing_type' => $record->billing_type->value,
        ];
    }

    public function recordLabour(LabourEntryData $data, User $user): MaintenanceLabourEntry
    {
        $record = MaintenanceRecord::query()->findOrFail($data->maintenanceRecordId);

        Gate::forUser($user)->authorize('recordCost', $record);

        if ($data->minutes <= 0) {
            throw ValidationException::withMessages(['minutes' => 'Labour minutes must be greater than zero.']);
        }

        $hourlyRateMinor = $data->hourlyRateMinor
            ?? User::query()->find($data->employeeId)?->employeeProfile?->default_hourly_rate_minor;

        if ($hourlyRateMinor === null) {
            throw ValidationException::withMessages([
                'hourly_rate_minor' => 'An hourly rate is required — this employee has no default rate configured.',
            ]);
        }

        $hourlyRateMinor = (int) $hourlyRateMinor;

        return DB::transaction(function () use ($record, $data, $hourlyRateMinor, $user): MaintenanceLabourEntry {
            $entry = MaintenanceLabourEntry::query()->create([
                'maintenance_record_id' => $record->getKey(),
                'service_record_id' => $data->serviceRecordId,
                'employee_id' => $data->employeeId,
                'performed_on' => $data->performedOn,
                'minutes' => $data->minutes,
                'hourly_rate_minor' => $hourlyRateMinor,
                'total_cost_minor' => (int) round($data->minutes / 60 * $hourlyRateMinor),
                'notes' => $data->notes,
                'created_by' => $user->getKey(),
            ]);

            activity()
                ->performedOn($entry)
                ->causedBy($user)
                ->withChanges(['attributes' => $entry->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('support.maintenance_labour_entry.recorded');

            return $entry;
        });
    }

    public function recordThirdPartyCost(ThirdPartyCostData $data, User $user): MaintenanceThirdPartyCost
    {
        $record = MaintenanceRecord::query()->findOrFail($data->maintenanceRecordId);

        Gate::forUser($user)->authorize('recordCost', $record);

        if ($data->amountMinor <= 0) {
            throw ValidationException::withMessages(['amount_minor' => 'The cost amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($record, $data, $user): MaintenanceThirdPartyCost {
            $cost = MaintenanceThirdPartyCost::query()->create([
                'maintenance_record_id' => $record->getKey(),
                'supplier_id' => $data->supplierId,
                'bill_id' => $data->billId,
                'description' => $data->description,
                'amount_minor' => $data->amountMinor,
                'incurred_on' => $data->incurredOn,
                'created_by' => $user->getKey(),
            ]);

            activity()
                ->performedOn($cost)
                ->causedBy($user)
                ->withChanges(['attributes' => $cost->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('support.maintenance_third_party_cost.recorded');

            return $cost;
        });
    }

    /** @return Collection<int, ServiceRecordPart> */
    private function activeParts(MaintenanceRecord $record): Collection
    {
        $record->loadMissing('serviceRecords.parts');

        return $record->serviceRecords
            ->flatMap(fn (MaintenanceTask $task): Collection => $task->parts)
            ->filter(fn (ServiceRecordPart $part): bool => $part->reversed_at === null)
            ->values();
    }
}
