<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\PlanTaskStatus;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\TaskStatusLog;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class PlanTaskService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(SalesPlan $plan, array $data): PlanTask
    {
        return DB::transaction(function () use ($plan, $data): PlanTask {
            $this->assertWithinPlanWindow($plan, $data['starts_at'], $data['due_at']);

            $task = PlanTask::query()->create([
                ...$data,
                'sales_plan_id' => $plan->id,
                'status' => PlanTaskStatus::Pending,
            ]);

            TaskStatusLog::query()->forceCreate([
                'plan_task_id' => $task->id,
                'from_status' => null,
                'to_status' => PlanTaskStatus::Pending->value,
                'actor_id' => auth()->id(),
                'created_at' => now(),
            ]);

            activity()
                ->performedOn($task)
                ->withChanges([
                    'attributes' => $task->getAttributes(),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('task.created');

            return $task;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PlanTask $task, array $data): PlanTask
    {
        return DB::transaction(function () use ($task, $data): PlanTask {
            $plan = $task->salesPlan;

            if (! $plan instanceof SalesPlan) {
                throw new LogicException('Expected a PlanTask to have a parent SalesPlan.');
            }

            $startsAt = $data['starts_at'] ?? $task->starts_at;
            $dueAt = $data['due_at'] ?? $task->due_at;
            $this->assertWithinPlanWindow($plan, $startsAt, $dueAt);

            $oldValues = $task->getAttributes();
            $task->update($data);

            activity()
                ->performedOn($task)
                ->withChanges([
                    'old' => $oldValues,
                    'attributes' => $task->getAttributes(),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('task.updated');

            return $task;
        });
    }

    public function transition(PlanTask $task, PlanTaskStatus $to, ?string $note = null): PlanTask
    {
        return DB::transaction(function () use ($task, $to, $note): PlanTask {
            $from = $task->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            if ($to === PlanTaskStatus::Completed) {
                $task->completed_at = now();
            } elseif ($from === PlanTaskStatus::Completed && $to === PlanTaskStatus::InProgress) {
                $task->completed_at = null;
            }

            $task->status = $to;
            $task->save();

            TaskStatusLog::query()->forceCreate([
                'plan_task_id' => $task->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'note' => $note,
                'actor_id' => auth()->id(),
                'created_at' => now(),
            ]);

            activity()
                ->performedOn($task)
                ->withChanges([
                    'old' => ['status' => $from->value],
                    'attributes' => ['status' => $to->value],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('task.transitioned');

            return $task;
        });
    }

    private function assertWithinPlanWindow(SalesPlan $plan, mixed $startsAt, mixed $dueAt): void
    {
        $monthStart = Carbon::parse($plan->month)->startOfMonth();
        $monthEnd = Carbon::parse($plan->month)->endOfMonth();
        $starts = $this->parseDate($startsAt);
        $due = $this->parseDate($dueAt);

        if ($starts->lt($monthStart) || $starts->gt($monthEnd) || $due->lt($monthStart) || $due->gt($monthEnd)) {
            throw new DomainException(__('admin.employees.errors.task_dates_outside_plan_window'));
        }
    }

    private function parseDate(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_string($value) || $value instanceof DateTimeInterface) {
            return Carbon::parse($value);
        }

        throw new DomainException(__('admin.employees.errors.task_dates_outside_plan_window'));
    }
}
