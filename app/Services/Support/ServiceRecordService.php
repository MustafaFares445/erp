<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

final readonly class ServiceRecordService
{
    /** @param array<string, mixed> $data */
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
                'work_performed' => $data['work_performed'] ?? null,
                'completion_notes' => $data['completion_notes'] ?? null,
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

    /** @param array<string, mixed> $data */
    public function update(MaintenanceTask $task, array $data, User $actor): MaintenanceTask
    {
        Gate::forUser($actor)->authorize('update', $task);

        if (in_array($task->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)) {
            throw new DomainException('Completed or cancelled service records cannot be edited.');
        }

        $dueAt = $this->parseDueAt($data['due_at'] ?? $task->due_at);
        $parentCreatedAt = $this->maintenanceRecordOf($task)->created_at;

        if ($dueAt instanceof Carbon && $parentCreatedAt instanceof Carbon && $dueAt->lt($parentCreatedAt)) {
            throw ValidationException::withMessages([
                'due_at' => 'The due date cannot be earlier than when the maintenance request was created.',
            ]);
        }

        return DB::transaction(function () use ($task, $data, $dueAt, $actor): MaintenanceTask {
            $oldValues = $task->only(['title', 'description', 'due_at', 'employee_id', 'work_performed', 'completion_notes']);

            $task->update([
                'title' => $data['title'] ?? $task->title,
                'description' => $data['description'] ?? $task->description,
                'due_at' => $dueAt,
                'employee_id' => $data['employee_id'] ?? $task->employee_id,
                'work_performed' => $data['work_performed'] ?? $task->work_performed,
                'completion_notes' => $data['completion_notes'] ?? $task->completion_notes,
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($task)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $oldValues,
                    'attributes' => $task->only(['title', 'description', 'due_at', 'employee_id', 'work_performed', 'completion_notes']),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.service_record.updated');

            return $task;
        });
    }

    public function transition(
        MaintenanceTask $task,
        MaintenanceStatus $to,
        User $actor,
        ?string $note = null,
        ?string $workPerformed = null,
    ): void {
        Gate::forUser($actor)->authorize('execute', $task);

        $from = $task->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidStatusTransition::fromTo($from->value, $to->value);
        }

        DB::transaction(function () use ($task, $from, $to, $actor, $note, $workPerformed): void {
            $attributes = [
                'status' => $to->value,
                'updated_by' => $actor->getKey(),
            ];

            if ($to === MaintenanceStatus::InProgress && $task->started_at === null) {
                $attributes['started_at'] = now();
            }

            if ($to === MaintenanceStatus::Closed) {
                $attributes['completed_at'] = now();

                if ($workPerformed !== null && mb_trim($workPerformed) !== '') {
                    $attributes['work_performed'] = mb_trim($workPerformed);
                }

                if ($note !== null && mb_trim($note) !== '') {
                    $attributes['completion_notes'] = mb_trim($note);
                }
            }

            $task->update($attributes);
            $this->cascadeParentToInProgress($task, $to, $actor);

            activity()
                ->performedOn($task)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => $to->value, 'note' => $note] + array_intersect_key($attributes, array_flip(['started_at', 'completed_at', 'work_performed'])),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.service_record.status_changed');
        });
    }

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

        if (! $record instanceof MaintenanceRecord) {
            throw new LogicException('A MaintenanceTask must always belong to a MaintenanceRecord.');
        }

        return $record;
    }
}
