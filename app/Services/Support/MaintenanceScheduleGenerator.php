<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceIntervalType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Enums\OccurrenceStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The daily preventive-maintenance sweep (WP-3.6, GAP-MW-08, MT-07):
 * raising occurrences that fall inside their schedule's lead time, and
 * marking overdue, never-raised occurrences `Missed` so a skipped service is
 * visible as a row rather than an absence.
 */
final readonly class MaintenanceScheduleGenerator
{
    public function __construct(
        private MaintenanceRecordService $recordService,
        private MaintenanceScheduleService $scheduleService,
        private NotificationDispatcher $dispatcher,
    ) {}

    /**
     * For every still-`Pending` occurrence due within its schedule's lead
     * time, creates the maintenance request through the one canonical
     * creation path ({@see MaintenanceRecordService::createStandalone()}),
     * links it, flips the occurrence to `Raised`, and notifies the
     * schedule's owner (WP-2.10's dispatch pattern). Idempotent: a second
     * run the same day re-selects only occurrences still `Pending` — one
     * already raised is skipped, and the unique `(schedule, due_on)` index
     * means a run can never create a duplicate occurrence to raise twice.
     * An authorization or validation failure for one occurrence propagates
     * immediately (occurrences already raised earlier in this call stay
     * committed) — the console command catches it and reports a failed run.
     */
    public function raiseDue(): int
    {
        $raised = 0;

        $occurrences = MaintenanceScheduleOccurrence::query()
            ->where('status', OccurrenceStatus::Pending->value)
            ->with(['schedule.serializedInventoryUnit', 'schedule.createdBy'])
            ->orderBy('due_on')
            ->get();

        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->schedule;
            if (! $schedule instanceof MaintenanceSchedule) {
                continue;
            }
            if (! $schedule->is_active) {
                continue;
            }

            $unit = $schedule->serializedInventoryUnit;

            if ($unit instanceof SerializedInventoryUnit && $unit->status === SerializedInventoryUnitStatus::Disposed) {
                continue;
            }

            if ($occurrence->due_on->gt(today()->addDays($schedule->lead_time_days))) {
                continue;
            }

            if ($this->raiseOccurrence($occurrence, $schedule)) {
                $raised++;
            }
        }

        return $raised;
    }

    /**
     * Past-due, never-raised occurrences become `Missed` — the state that
     * makes a skipped preventive service distinguishable from one simply not
     * due yet.
     */
    public function markMissed(): int
    {
        return MaintenanceScheduleOccurrence::query()
            ->where('status', OccurrenceStatus::Pending->value)
            ->where('due_on', '<', today())
            ->update(['status' => OccurrenceStatus::Missed->value]);
    }

    /**
     * Completes the occurrence linked to a just-closed {@see MaintenanceRecord}
     * (called from {@see MaintenanceRecordService::transition()} when a job
     * closes), updates the schedule's `last_completed_on`, and extends the
     * bounded occurrence horizon by one so it stays full.
     */
    public function completeForRecord(MaintenanceRecord $record): void
    {
        $occurrence = MaintenanceScheduleOccurrence::query()
            ->where('maintenance_record_id', $record->getKey())
            ->where('status', OccurrenceStatus::Raised->value)
            ->first();

        if (! $occurrence instanceof MaintenanceScheduleOccurrence) {
            return;
        }

        $schedule = $occurrence->schedule;

        if (! $schedule instanceof MaintenanceSchedule) {
            return;
        }

        DB::transaction(function () use ($occurrence, $schedule): void {
            $occurrence->forceFill(['status' => OccurrenceStatus::Completed->value, 'completed_at' => now()])->save();
            $schedule->forceFill(['last_completed_on' => $occurrence->due_on])->save();

            if ($schedule->is_active) {
                $this->extendHorizon($schedule);
            }
        });
    }

    /**
     * Records a decision not to perform an occurrence — never a deletion.
     *
     * @throws ValidationException when `$reason` is blank
     * @throws DomainException when the occurrence is not `Pending`/`Missed`
     */
    public function skip(MaintenanceScheduleOccurrence $occurrence, User $actor, string $reason): MaintenanceScheduleOccurrence
    {
        $schedule = $occurrence->schedule;

        if ($schedule instanceof MaintenanceSchedule) {
            Gate::forUser($actor)->authorize('update', $schedule);
        }

        if (mb_trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required to skip a preventive maintenance occurrence.',
            ]);
        }

        if (! in_array($occurrence->status, [OccurrenceStatus::Pending, OccurrenceStatus::Missed], true)) {
            throw new DomainException('Only a pending or missed occurrence can be skipped.');
        }

        return DB::transaction(function () use ($occurrence, $actor, $reason): MaintenanceScheduleOccurrence {
            $occurrence->forceFill([
                'status' => OccurrenceStatus::Skipped->value,
                'skipped_reason' => $reason,
            ])->save();

            activity()
                ->performedOn($occurrence)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'reason' => $reason])
                ->log('support.maintenance_schedule_occurrence.skipped');

            return $occurrence->refresh();
        });
    }

    /**
     * @return bool whether this occurrence actually raised (false when a
     *              concurrent run already flipped it away from `Pending`)
     */
    private function raiseOccurrence(MaintenanceScheduleOccurrence $occurrence, MaintenanceSchedule $schedule): bool
    {
        $actor = $schedule->createdBy;

        if (! $actor instanceof User) {
            return false;
        }

        $raisedRecord = DB::transaction(function () use ($occurrence, $schedule, $actor): ?MaintenanceRecord {
            // Idempotency guard: re-check under a row lock in case a concurrent
            // sweep raised this same occurrence between the outer query and here.
            $locked = MaintenanceScheduleOccurrence::query()
                ->whereKey($occurrence->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof MaintenanceScheduleOccurrence || $locked->status !== OccurrenceStatus::Pending) {
                return null;
            }

            $unit = $schedule->serializedInventoryUnit;

            // createStandalone() always creates the job `Unbilled`, like any other
            // maintenance request — the schedule's configured billing_type
            // (WarrantyCovered/Quoted/Invoiced) is only ever a settled state applied
            // by MaintenanceBillingService's own guarded transitions once the job
            // closes, never written directly here. The schedule's billing_type is
            // the intended billing path for whoever closes the job out.
            $record = $this->recordService->createStandalone([
                'customer_id' => $schedule->customer_id,
                'description' => sprintf('Preventive maintenance due: %s (%s)', $schedule->name, $schedule->schedule_number),
                'serial_number' => $unit?->serial_number,
            ], $actor);

            $locked->forceFill([
                'status' => OccurrenceStatus::Raised->value,
                'maintenance_record_id' => $record->getKey(),
                'raised_at' => now(),
            ])->save();

            return $record;
        });

        if (! $raisedRecord instanceof MaintenanceRecord) {
            return false;
        }

        $this->dispatcher->dispatch(
            $actor,
            NotificationEventKey::MaintenanceDue,
            [
                'schedule_number' => (string) $schedule->schedule_number,
                'schedule_name' => (string) $schedule->name,
                'due_on' => $occurrence->due_on->toDateString(),
            ],
            $schedule,
            NotificationChannel::Mail,
        );

        return true;
    }

    /**
     * Adds exactly one new occurrence past the schedule's latest due date —
     * one completed, one added, so the bounded horizon stays the same size
     * instead of growing by a full new horizon on every completion.
     * `UsageHours` schedules have no calendar arithmetic
     * ({@see MaintenanceIntervalType::advance()}) and are left for manual
     * due-date advancement.
     */
    private function extendHorizon(MaintenanceSchedule $schedule): void
    {
        if ($schedule->interval_type === MaintenanceIntervalType::UsageHours) {
            return;
        }

        $latestDueOn = $schedule->occurrences()->max('due_on');
        $anchor = is_string($latestDueOn) ? Carbon::parse($latestDueOn) : $schedule->first_due_on;
        $next = $schedule->interval_type->advance($anchor, $schedule->interval_value);

        $this->scheduleService->ensureOccurrence($schedule, $next);
        $this->scheduleService->refreshNextDueOn($schedule);
    }
}
