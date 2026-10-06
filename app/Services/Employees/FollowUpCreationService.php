<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\PlanTaskStatus;
use App\Events\FollowUpTaskCreated;
use App\Models\CustomerVisit;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\TaskStatusLog;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class FollowUpCreationService
{
    public function createForVisit(CustomerVisit $visit): PlanTask
    {
        return DB::transaction(function () use ($visit): PlanTask {
            $existing = PlanTask::query()->where('source_visit_id', $visit->getKey())->first();

            if ($existing instanceof PlanTask) {
                return $existing;
            }

            if (! $visit->follow_up_required || $visit->follow_up_date === null) {
                throw new DomainException('A follow-up date is required before creating a follow-up task.');
            }

            $sourceTask = $visit->planTask;
            $plan = $sourceTask?->salesPlan;

            if (! $plan instanceof SalesPlan) {
                throw new LogicException('The visit must belong to a monthly plan task.');
            }

            $task = PlanTask::query()->create([
                'sales_plan_id' => $plan->getKey(),
                'customer_id' => $visit->customer_id,
                'source_visit_id' => $visit->getKey(),
                'title' => 'Visit follow-up: '.($visit->customer->company_name ?? $visit->reference ?? '#'.$visit->id),
                'description' => $visit->follow_up_note ?: $visit->outcome_notes,
                'starts_at' => $visit->follow_up_date,
                'due_at' => $visit->follow_up_date,
                'status' => PlanTaskStatus::Pending,
            ]);

            TaskStatusLog::query()->forceCreate([
                'plan_task_id' => $task->getKey(),
                'from_status' => null,
                'to_status' => PlanTaskStatus::Pending->value,
                'note' => 'Created automatically from visit follow-up.',
                'actor_id' => auth()->id(),
                'created_at' => now(),
            ]);

            activity()->performedOn($task)
                ->withChanges(['attributes' => $task->getAttributes()])
                ->withProperties(['source_channel' => 'system', 'source_visit_id' => $visit->getKey()])
                ->log('visit.follow_up_task_created');

            DB::afterCommit(static function () use ($task): void {
                $task->refresh()->load('salesPlan.employee.user');
                FollowUpTaskCreated::dispatch($task);
            });

            return $task;
        });
    }
}
