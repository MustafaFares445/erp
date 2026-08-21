<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Service record ("Maintenance Task") creation and the cascading transition
 * rule (FR-070–076, contracts/maintenance-lifecycle.md §3).
 */
final readonly class ServiceRecordService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(MaintenanceRecord $record, array $data, User $actor): MaintenanceTask
    {
        Gate::forUser($actor)->authorize('create', MaintenanceTask::class);

        $dueAt = $this->parseDueAt($data['due_at'] ?? null);

        if ($dueAt instanceof Carbon && $record->created_at instanceof Carbon && $dueAt->lt($record->created_at)) {
            throw ValidationException::withMessages([
                'due_at' => 'The due date cannot be earlier than when the maintenance request was created.',
            ]);
        }

        return DB::transaction(function () use ($record, $data, $dueAt, $actor): MaintenanceTask {
            $task = MaintenanceTask::query()->create([
                'maintenance_record_id' => $record->getKey(),
                'employee_id' => $data['employee_id'] ?? null,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'due_at' => $dueAt,
                'status' => MaintenanceStatus::Open,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($task)
                ->causedBy($actor)
                ->withChanges(['attributes' => $task->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.service_record.created');

            return $task;
        });
    }

    /**
     * Corrects the title, description, due date, or assignee — a
     * Manager-unrestricted action, not a status transition
     * (that's {@see self::transition()}).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(MaintenanceTask $task, array $data, User $actor): MaintenanceTask
    {
        Gate::forUser($actor)->authorize('update', $task);

        $dueAt = $this->parseDueAt($data['due_at'] ?? $task->due_at);
        $parentCreatedAt = $this->maintenanceRecordOf($task)->created_at;

        if ($dueAt instanceof Carbon && $parentCreatedAt instanceof Carbon && $dueAt->lt($parentCreatedAt)) {
            throw ValidationException::withMessages([
                'due_at' => 'The due date cannot be earlier than when the maintenance request was created.',
            ]);
        }

        return DB::transaction(function () use ($task, $data, $dueAt, $actor): MaintenanceTask {
            $oldValues = $task->only(['title', 'description', 'due_at', 'employee_id']);

            $task->update([
                'title' => $data['title'] ?? $task->title,
                'description' => $data['description'] ?? $task->description,
                'due_at' => $dueAt,
                'employee_id' => $data['employee_id'] ?? $task->employee_id,
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($task)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $oldValues,
                    'attributes' => $task->only(['title', 'description', 'due_at', 'employee_id']),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.service_record.updated');

            return $task;
        });
    }

    /**
     * @throws InvalidStatusTransition when `$from->canTransitionTo($to)` is false
     */
    public function transition(MaintenanceTask $task, MaintenanceStatus $to, User $actor, ?string $note = null): void
    {
        Gate::forUser($actor)->authorize('execute', $task);

        $from = $task->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidStatusTransition::fromTo($from->value, $to->value);
        }

        DB::transaction(function () use ($task, $from, $to, $actor, $note): void {
            $task->update(['status' => $to->value, 'updated_by' => $actor->getKey()]);

            $this->cascadeParentToInProgress($task, $to, $actor);

            activity()
                ->performedOn($task)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => $to->value, 'note' => $note],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.service_record.status_changed');
        });
    }

    /**
     * The first service record under an `open` request to reach
     * `in_progress` cascades the parent to `in_progress` too (FR-074).
     * Idempotent by construction — once the parent leaves `open`, no later
     * task reaching `in_progress` re-triggers this.
     */
    private function cascadeParentToInProgress(MaintenanceTask $task, MaintenanceStatus $to, User $actor): void
    {
        if ($to !== MaintenanceStatus::InProgress) {
            return;
        }

        $record = $this->maintenanceRecordOf($task);

        if ($record->status === MaintenanceStatus::Open) {
            $record->update(['status' => MaintenanceStatus::InProgress->value, 'updated_by' => $actor->getKey()]);

            activity()
                ->performedOn($record)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => MaintenanceStatus::Open->value],
                    'attributes' => ['status' => MaintenanceStatus::InProgress->value, 'cascaded_from_service_record_id' => $task->getKey()],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.maintenance_record.status_changed');
        }
    }

    private function parseDueAt(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    private function maintenanceRecordOf(MaintenanceTask $task): MaintenanceRecord
    {
        $record = $task->maintenanceRecord;

        // @codeCoverageIgnoreStart
        // maintenance_tasks.maintenance_record_id is NOT NULL and foreign-key constrained.
        if (! $record instanceof MaintenanceRecord) {
            throw new LogicException('A MaintenanceTask must always belong to a MaintenanceRecord.');
        }

        // @codeCoverageIgnoreEnd

        return $record;
    }
}
