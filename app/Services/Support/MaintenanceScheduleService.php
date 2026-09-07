<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\MaintenanceScheduleData;
use App\Enums\MaintenanceIntervalType;
use App\Enums\OccurrenceStatus;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\User;
use App\Services\Sales\DocumentNumberGenerator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Preventive-maintenance recurrence programme lifecycle (WP-3.6,
 * GAP-MW-08, MT-07). Creating a schedule generates a bounded set of
 * occurrences (12 months or 12 occurrences, whichever is shorter) so the
 * calendar is visible without generating indefinitely into the future —
 * {@see MaintenanceScheduleGenerator} extends that horizon one occurrence at
 * a time as each is completed.
 */
final readonly class MaintenanceScheduleService
{
    /**
     * The bounded horizon's occurrence-count cap (paired with a 12-month
     * date cap in {@see self::generateHorizon()}) — "whichever is shorter".
     */
    private const int MAX_HORIZON_OCCURRENCES = 12;

    public function __construct(
        private DocumentNumberGenerator $numbers,
    ) {}

    public function create(MaintenanceScheduleData $data, User $actor): MaintenanceSchedule
    {
        Gate::forUser($actor)->authorize('create', MaintenanceSchedule::class);

        // customer_id stays nullable on the table (nullOnDelete survives a customer
        // being deleted later) but is required at creation — every MaintenanceRecord
        // this schedule raises needs one (its own customer_id is NOT NULL).
        if ($data->customerId === null) {
            throw ValidationException::withMessages([
                'customer_id' => 'A customer is required to create a preventive maintenance schedule.',
            ]);
        }

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

            $oldValues = $schedule->only(['name', 'interval_type', 'interval_value', 'lead_time_days', 'billing_type', 'customer_id']);

            $schedule->forceFill([
                'name' => $data->name,
                'customer_id' => $data->customerId,
                'interval_type' => $data->intervalType,
                'interval_value' => $data->intervalValue,
                'lead_time_days' => $data->leadTimeDays,
                'billing_type' => $data->billingType,
                'checklist' => $data->checklist,
                'updated_by' => $actor->getKey(),
            ])->save();

            // A changed recurrence invalidates the not-yet-raised part of the calendar —
            // regenerate it from the last completed date (or the first due date when
            // nothing has completed yet) rather than leaving stale pending occurrences
            // that no longer reflect the new interval.
            if ($intervalChanged) {
                $schedule->occurrences()->where('status', OccurrenceStatus::Pending->value)->delete();
                $anchor = $schedule->last_completed_on instanceof Carbon ? $schedule->last_completed_on : $schedule->first_due_on;
                $this->generateHorizon($schedule, $anchor);
                $this->refreshNextDueOn($schedule);
            }

            activity()
                ->performedOn($schedule)
                ->causedBy($actor)
                ->withChanges(['old' => $oldValues, 'attributes' => $schedule->only(['name', 'interval_type', 'interval_value', 'lead_time_days', 'billing_type', 'customer_id'])])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('support.maintenance_schedule.updated');

            return $schedule->refresh();
        });
    }

    /**
     * Stops generation without deleting any history — existing occurrences
     * (including past `Missed`/`Completed` rows) are left untouched.
     */
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

    /**
     * Creates one `Pending` occurrence per due date starting at `$from`,
     * stopping at whichever bound is reached first: 12 occurrences, or a
     * due date more than 12 months after `$from`. A `UsageHours` schedule
     * has no calendar arithmetic ({@see MaintenanceIntervalType::advance()}),
     * so it gets exactly one occurrence — its due date must be advanced
     * manually from usage telemetry.
     */
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

    /**
     * Keeps `next_due_on` in sync with the earliest still-`Pending`
     * occurrence — the column {@see MaintenanceSchedule} documents as
     * service-owned.
     */
    public function refreshNextDueOn(MaintenanceSchedule $schedule): void
    {
        $earliest = $schedule->occurrences()
            ->where('status', OccurrenceStatus::Pending->value)
            ->min('due_on');

        if ($earliest !== null) {
            $schedule->forceFill(['next_due_on' => $earliest])->save();
        }
    }

    /**
     * Creates a single `Pending` occurrence for `$dueOn` if one doesn't
     * already exist — the unique `(schedule, due_on)` index makes this the
     * only safe way to add an occurrence, so both {@see self::generateHorizon()}
     * and {@see MaintenanceScheduleGenerator}'s post-completion extension use
     * it rather than a bare `firstOrCreate()` against a fully-guarded model.
     */
    public function ensureOccurrence(MaintenanceSchedule $schedule, Carbon $dueOn): void
    {
        $exists = MaintenanceScheduleOccurrence::query()
            ->where('maintenance_schedule_id', $schedule->getKey())
            ->where('due_on', $dueOn->toDateString())
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
}
