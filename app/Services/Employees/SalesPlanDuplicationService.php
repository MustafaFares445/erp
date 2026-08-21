<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\SalesPlanStatus;
use App\Models\CustomerProfile;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Copies a plan into a new, fully independent `sales_plans` row — to
 * another month (FR-024) or another employee (FR-020) — per D9 /
 * contracts/plan-lifecycle.md.
 */
final readonly class SalesPlanDuplicationService
{
    public function duplicate(SalesPlan $source, int $targetEmployeeId, CarbonImmutable $targetMonth): SalesPlan
    {
        $targetMonth = $targetMonth->startOfMonth();

        $conflict = SalesPlan::query()
            ->where('employee_id', $targetEmployeeId)
            ->whereDate('active_month', $targetMonth->toDateString())
            ->first();

        if ($conflict instanceof SalesPlan) {
            throw new DomainException(__('admin.employees.errors.plan_copy_target_conflict', [
                'plan' => $conflict->name,
            ]));
        }

        return DB::transaction(function () use ($source, $targetEmployeeId, $targetMonth): SalesPlan {
            $copy = SalesPlan::query()->create([
                'employee_id' => $targetEmployeeId,
                'name' => $source->name,
                'month' => $targetMonth->toDateString(),
                'active_month' => null,
                'task_weight' => $source->task_weight,
                'visit_weight' => $source->visit_weight,
                'schedule_weight' => $source->schedule_weight,
                'work_time_weight' => $source->work_time_weight,
                'required_visit_minutes' => $source->required_visit_minutes,
                'status' => SalesPlanStatus::Draft,
            ]);

            foreach ($source->tasks as $task) {
                $customerId = $this->stillActiveCustomerId($task->customer_id);

                PlanTask::query()->create([
                    'sales_plan_id' => $copy->id,
                    'customer_id' => $customerId,
                    'title' => $task->title,
                    'description' => $task->description,
                    'starts_at' => $this->rebase($task->starts_at, $targetMonth),
                    'due_at' => $this->rebase($task->due_at, $targetMonth),
                    'completed_at' => null,
                    'status' => 'Pending',
                ]);
            }

            activity()
                ->performedOn($copy)
                ->withChanges([
                    'old' => ['source_plan_id' => $source->id],
                    'attributes' => [
                        'target_employee_id' => $targetEmployeeId,
                        'target_month' => $targetMonth->toDateString(),
                    ],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('plan.copied');

            return $copy;
        });
    }

    private function rebase(CarbonInterface $date, CarbonImmutable $targetMonth): string
    {
        $dayOfMonth = min($date->day, $targetMonth->daysInMonth);

        return $targetMonth->setDay($dayOfMonth)->toDateString();
    }

    private function stillActiveCustomerId(?int $customerId): ?int
    {
        if ($customerId === null) {
            return null;
        }

        $isActive = CustomerProfile::query()->where('id', $customerId)->where('is_active', true)->exists();

        return $isActive ? $customerId : null;
    }
}
