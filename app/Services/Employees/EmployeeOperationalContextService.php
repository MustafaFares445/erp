<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\PlanTaskStatus;
use App\Enums\VisitStatus;
use App\Models\CustomerVisit;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\PlanTask;
use App\Models\SalesOpportunity;
use App\Models\SalesPlan;
use Illuminate\Database\Eloquent\Builder;

final readonly class EmployeeOperationalContextService
{
    /** @return array<string, string|int|float|null> */
    public function overview(EmployeeProfile $employee): array
    {
        $currentPlan = SalesPlan::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('month', now()->startOfMonth()->toDateString())
            ->latest('id')
            ->first();

        $score = $currentPlan?->performanceScore()->latest('calculated_at')->first();
        $salary = $currentPlan?->salaryCalculations()->latest('id')->first();
        $nextVisit = CustomerVisit::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', [VisitStatus::Scheduled->value, VisitStatus::EnRoute->value])
            ->where('scheduled_start_at', '>=', now())
            ->orderBy('scheduled_start_at')
            ->first();
        $lastVisit = CustomerVisit::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', VisitStatus::Completed->value)
            ->latest('checked_out_at')
            ->first();

        $taskQuery = PlanTask::query()->whereHas(
            'salesPlan',
            static fn (Builder $query): Builder => $query->where('employee_id', $employee->getKey()),
        );

        return [
            'current_plan' => $currentPlan?->name,
            'current_plan_status' => $currentPlan?->status->label(),
            'current_month' => $currentPlan?->month?->format('F Y'),
            'current_performance' => $score !== null ? (float) $score->total_score : null,
            'current_salary_status' => $salary?->status->label(),
            'current_salary' => $salary !== null ? (float) $salary->final_salary : null,
            'tasks_open' => (clone $taskQuery)->whereNotIn('status', [PlanTaskStatus::Completed->value, PlanTaskStatus::Cancelled->value])->count(),
            'tasks_completed' => (clone $taskQuery)->where('status', PlanTaskStatus::Completed->value)->count(),
            'visits_completed' => CustomerVisit::query()->where('employee_id', $employee->getKey())->where('status', VisitStatus::Completed->value)->count(),
            'location_warnings' => CustomerVisit::query()->where('employee_id', $employee->getKey())->where('location_warning', true)->count(),
            'opportunities' => SalesOpportunity::query()->whereHas(
                'sourceVisit',
                static fn (Builder $query): Builder => $query->where('employee_id', $employee->getKey()),
            )->count(),
            'next_visit' => $nextVisit?->effectiveScheduledStart()?->format('Y-m-d H:i'),
            'next_visit_customer' => $nextVisit?->customer?->company_name,
            'last_visit' => $lastVisit?->checked_out_at?->format('Y-m-d H:i'),
            'last_visit_customer' => $lastVisit?->customer?->company_name,
        ];
    }

    /** @return array<int, array<string, string|int|float|null>> */
    public function plans(EmployeeProfile $employee): array
    {
        return SalesPlan::query()
            ->where('employee_id', $employee->getKey())
            ->withCount('tasks')
            ->latest('month')
            ->limit(12)
            ->get()
            ->map(static fn (SalesPlan $plan): array => [
                'name' => $plan->name,
                'month' => $plan->month->format('F Y'),
                'status' => $plan->status->label(),
                'tasks' => $plan->tasks_count,
                'performance' => $plan->performanceSummary()['total_score'] ?? null,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, string|int|float|null>> */
    public function visits(EmployeeProfile $employee): array
    {
        return CustomerVisit::query()
            ->where('employee_id', $employee->getKey())
            ->with('customer:id,company_name')
            ->orderByDesc('scheduled_start_at')
            ->limit(20)
            ->get()
            ->map(static fn (CustomerVisit $visit): array => [
                'reference' => $visit->reference ?? '#'.$visit->id,
                'customer' => $visit->customer?->company_name,
                'scheduled_at' => $visit->effectiveScheduledStart()?->format('Y-m-d H:i'),
                'status' => $visit->status->label(),
                'outcome' => $visit->outcome_code?->label(),
                'follow_up' => $visit->follow_up_required ? self::translation('Yes') : self::translation('No'),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, string|int|float|null>> */
    public function tasks(EmployeeProfile $employee): array
    {
        return PlanTask::query()
            ->whereHas('salesPlan', static fn (Builder $query): Builder => $query->where('employee_id', $employee->getKey()))
            ->with(['salesPlan:id,name', 'customer:id,company_name'])
            ->latest('due_at')
            ->limit(20)
            ->get()
            ->map(static fn (PlanTask $task): array => [
                'title' => $task->title,
                'plan' => $task->salesPlan?->name,
                'customer' => $task->customer?->company_name,
                'due_at' => $task->due_at->toDateString(),
                'status' => $task->status->value,
                'source' => $task->source_visit_id !== null ? self::translation('Visit follow-up') : self::translation('Monthly plan'),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, string|int|float|null>> */
    public function performance(EmployeeProfile $employee): array
    {
        return EmployeePerformanceScore::query()
            ->where('employee_id', $employee->getKey())
            ->with('salesPlan:id,name,month')
            ->latest('calculated_at')
            ->limit(12)
            ->get()
            ->map(static fn (EmployeePerformanceScore $score): array => [
                'plan' => $score->salesPlan?->name,
                'period' => $score->period_start?->format('Y-m-d').' → '.$score->period_end?->format('Y-m-d'),
                'total' => (float) $score->total_score,
                'task' => (float) $score->task_score,
                'visit' => (float) $score->visit_score,
                'schedule' => (float) $score->schedule_score,
                'work_time' => (float) $score->work_time_score,
                'opportunity' => (float) $score->opportunity_score,
                'calculated_at' => $score->calculated_at->format('Y-m-d H:i'),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, string|int|float|null>> */
    public function salaries(EmployeeProfile $employee): array
    {
        return EmployeeSalaryCalculation::query()
            ->where('employee_id', $employee->getKey())
            ->with('salesPlan:id,name,month')
            ->latest('id')
            ->limit(12)
            ->get()
            ->map(static fn (EmployeeSalaryCalculation $calculation): array => [
                'plan' => $calculation->salesPlan?->name,
                'status' => $calculation->status->label(),
                'base' => (float) $calculation->payable_base,
                'performance' => (float) $calculation->performance_percent,
                'bonus' => (float) $calculation->bonus_amount,
                'final' => (float) $calculation->final_salary,
                'confirmed_at' => $calculation->getAttribute('confirmed_at') instanceof \DateTimeInterface
                    ? $calculation->getAttribute('confirmed_at')->format('Y-m-d H:i')
                    : null,
            ])
            ->values()
            ->all();
    }

    private static function translation(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }
}
