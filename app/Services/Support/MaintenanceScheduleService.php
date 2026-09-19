<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\MaintenanceScheduleData;
use App\Enums\MaintenanceIntervalType;
use App\Enums\OccurrenceStatus;
use App\Enums\SerializedCustodyType;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Sales\DocumentNumberGenerator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Preventive-maintenance recurrence programme lifecycle.
 */
final readonly class MaintenanceScheduleService
{
    private const int MAX_HORIZON_OCCURRENCES = 12;

    public function __construct(
        private DocumentNumberGenerator $numbers,
    ) {}

    public function create(MaintenanceScheduleData $data, User $actor): MaintenanceSchedule
    {
        Gate::forUser($actor)->authorize('create', MaintenanceSchedule::class);

        if ($data->customerId === null) {
            throw ValidationException::withMessages([
                'customer_id' => 'A customer is required to create a preventive maintenance schedule.',
            ]);
        }

        $this->assertEquipmentBelongsToCustomer($data->serializedInventoryUnitId, $data->customerId);

        return DB::transaction(function () use ($data, $actor): MaintenanceSchedule {
            $firstDueOn = Carbon::parse($data->firstDueOn)->startOfDay();

            $schedule = MaintenanceSchedule::query()->forceCreate([
                'schedule_number' => $this->numbers->next(MaintenanceSchedule::query()->withTrashed(), 'schedule_number', 'MSCH-'),
                'serialized_inventory_unit_id' => $data->serializedInventoryUnitId,
                'customer_id' => $data->customerId,
                'name' => $data->name,
                'interval_type' => $data->intervalType,
                'interval_value' => $data->intervalValue,
                'lead_time_days' => $data->leadTimeDays,
                'first_due_on' => $firstDueOn,
                'next_due_on' => $firstDueOn,
                'is_active' => true,
                'billing_type' => $data->billingType,
                'checklist' => $data->checklist,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->generateHorizon($schedule, $firstDueOn);
            $this->refreshNextDueOn($schedule);

            activity()
                ->performedOn($schedule)
                ->causedBy($actor)
                ->withChanges(['attributes' => $schedule->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('support.maintenance_schedule.created');

            return $schedule->refresh();
        });
    }

    public function update(MaintenanceSchedule $schedule, MaintenanceScheduleData $data, User $actor): MaintenanceSchedule
    {
        Gate::forUser($actor)->authorize('update', $schedule);

        return DB::transaction(function () use ($schedule, $data, $actor): MaintenanceSchedule {
            $intervalChanged = $schedule->interval_type !== $data->intervalType
                || $schedule->interval_value !== $data->intervalValue;

            $oldValues = $schedule->only(['name', 'interval_type', 'interval_value', 'lead_time_days', 'billing_type']);

            $schedule->forceFill([
                'name' => $data->name,
                'interval_type' => $data->intervalType,
                'interval_value' => $data->intervalValue,
                'lead_time_days' => $data->leadTimeDays,
                'billing_type' => $data->billingType,
                'checklist' => $data->checklist,
                'updated_by' => $actor->getKey(),
            ])->save();

            if ($intervalChanged) {
                $schedule->occurrences()->where('status', OccurrenceStatus::Pending->value)->delete();
                $anchor = $schedule->last_completed_on instanceof Carbon ? $schedule->last_completed_on : $schedule->first_due_on;
                $this->generateHorizon($schedule, $anchor);
                $this->refreshNextDueOn($schedule);
            }

            activity()
                ->performedOn($schedule)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $oldValues,
                    'attributes' => $schedule->only(['name', 'interval_type', 'interval_value', 'lead_time_days', 'billing_type']),
                ])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('support.maintenance_schedule.updated');

            return $schedule->refresh();
        });
    }

    public function deactivate(MaintenanceSchedule $schedule, User $actor): MaintenanceSchedule
    {
        Gate::forUser($actor)->authorize('update', $schedule);

        return DB::transaction(function () use ($schedule, $actor): MaintenanceSchedule {
            $schedule->forceFill(['is_active' => false, 'updated_by' => $actor->getKey()])->save();

            activity()
                ->performedOn($schedule)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('support.maintenance_schedule.deactivated');

            return $schedule->refresh();
        });
    }

    public function generateHorizon(MaintenanceSchedule $schedule, Carbon $from): int
    {
        if ($schedule->interval_type === MaintenanceIntervalType::UsageHours) {
            $this->ensureOccurrence($schedule, $from);

            return 1;
        }

        $horizonEnd = $from->copy()->addYear();
        $due = $from->copy();
        $created = 0;

        while ($created < self::MAX_HORIZON_OCCURRENCES && $due->lte($horizonEnd)) {
            $this->ensureOccurrence($schedule, $due);
            $created++;
            $due = $schedule->interval_type->advance($due, $schedule->interval_value);
        }

        return $created;
    }

    public function refreshNextDueOn(MaintenanceSchedule $schedule): void
    {
        $earliest = $schedule->occurrences()
            ->where('status', OccurrenceStatus::Pending->value)
            ->min('due_on');

        if ($earliest !== null) {
            $schedule->forceFill(['next_due_on' => $earliest])->save();
        }
    }

    public function ensureOccurrence(MaintenanceSchedule $schedule, Carbon $dueOn): void
    {
        $exists = MaintenanceScheduleOccurrence::query()
            ->where('maintenance_schedule_id', $schedule->getKey())
            ->whereDate('due_on', $dueOn->toDateString())
            ->exists();

        if ($exists) {
            return;
        }

        MaintenanceScheduleOccurrence::query()->forceCreate([
            'maintenance_schedule_id' => $schedule->getKey(),
            'due_on' => $dueOn->toDateString(),
            'status' => OccurrenceStatus::Pending->value,
        ]);
    }

    private function assertEquipmentBelongsToCustomer(int $unitId, int $customerId): void
    {
        $belongsToCustomer = SerializedInventoryUnit::query()
            ->whereKey($unitId)
            ->where('custody_type', SerializedCustodyType::Customer->value)
            ->where('custody_reference_id', $customerId)
            ->exists();

        if (! $belongsToCustomer) {
            throw ValidationException::withMessages([
                'serialized_inventory_unit_id' => 'The selected equipment is not in this customer custody.',
            ]);
        }
    }
}
