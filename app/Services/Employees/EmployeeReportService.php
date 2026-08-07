<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\EmployeePermission;
use App\Enums\EmployeeReportType;
use App\Enums\VisitStatus;
use App\Models\CustomerVisit;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeSalaryCalculation;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aggregates the seven employee report types (FR-071, FR-072), following
 * `InventoryReportService`'s query-builder pattern.
 */
final readonly class EmployeeReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public function query(EmployeeReportType $type, array $filters = []): Builder
    {
        $filters = $this->normalizeFilters($filters);

        return match ($type) {
            EmployeeReportType::PlanCompletion => $this->planCompletionQuery($filters),
            EmployeeReportType::OverdueTasks => $this->overdueTasksQuery($filters),
            EmployeeReportType::UnexecutedVisits => $this->unexecutedVisitsQuery($filters),
            EmployeeReportType::PerformanceByEmployee => $this->performanceQuery($filters)->orderBy('employee_id'),
            EmployeeReportType::PerformanceByMonth => $this->performanceQuery($filters),
            EmployeeReportType::SalaryByEmployee => $this->salaryQuery($filters)->orderBy('employee_id'),
            EmployeeReportType::SalaryByMonth => $this->salaryQuery($filters),
        };
    }

    /** @return list<EmployeeReportType> */
    public function availableReports(User $actor): array
    {
        return array_values(array_filter(
            EmployeeReportType::cases(),
            fn (EmployeeReportType $type): bool => $this->canView($actor, $type),
        ));
    }

    public function canView(User $actor, EmployeeReportType $type): bool
    {
        return $actor->can(EmployeePermission::ReportView->value)
            && $actor->can($this->sourcePermission($type)->value);
    }

    /** @throws DomainException */
    public function authorizeView(User $actor, EmployeeReportType $type): void
    {
        if (! $this->canView($actor, $type)) {
            throw new DomainException(__('admin.employees.errors.report_unauthorized'));
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int|string>
     */
    public function normalizeFilters(array $filters): array
    {
        $normalized = [];

        if (isset($filters['employee_id']) && is_numeric($filters['employee_id']) && (int) $filters['employee_id'] > 0) {
            $normalized['employee_id'] = (int) $filters['employee_id'];
        }

        if (isset($filters['month']) && is_string($filters['month']) && $filters['month'] !== '') {
            $normalized['month'] = $filters['month'];
        }

        return $normalized;
    }

    private function sourcePermission(EmployeeReportType $type): EmployeePermission
    {
        return match ($type) {
            EmployeeReportType::PlanCompletion => EmployeePermission::PlanView,
            EmployeeReportType::OverdueTasks => EmployeePermission::TaskView,
            EmployeeReportType::UnexecutedVisits => EmployeePermission::VisitView,
            EmployeeReportType::PerformanceByEmployee, EmployeeReportType::PerformanceByMonth => EmployeePermission::PerformanceView,
            EmployeeReportType::SalaryByEmployee, EmployeeReportType::SalaryByMonth => EmployeePermission::SalaryView,
        };
    }

    /**
     * @param  array<string, int|string>  $filters
     * @return Builder<SalesPlan>
     */
    private function planCompletionQuery(array $filters): Builder
    {
        $query = SalesPlan::query()->with('tasks');
        $this->whereEmployee($query, $filters);
        $this->whereMonth($query, $filters, 'month');

        return $query;
    }

    /**
     * @param  array<string, int|string>  $filters
     * @return Builder<PlanTask>
     */
    private function overdueTasksQuery(array $filters): Builder
    {
        $query = PlanTask::query()->with('salesPlan.employee.user')->overdue();

        if (isset($filters['employee_id'])) {
            $query->whereHas('salesPlan', fn (Builder $plan): Builder => $plan->where('employee_id', $filters['employee_id']));
        }

        return $query;
    }

    /**
     * @param  array<string, int|string>  $filters
     * @return Builder<CustomerVisit>
     */
    private function unexecutedVisitsQuery(array $filters): Builder
    {
        $query = CustomerVisit::query()
            ->with(['employee.user', 'customer'])
            ->whereIn('status', [VisitStatus::Planned, VisitStatus::Missed]);
        $this->whereEmployee($query, $filters);

        return $query;
    }

    /**
     * @param  array<string, int|string>  $filters
     * @return Builder<EmployeePerformanceScore>
     */
    private function performanceQuery(array $filters): Builder
    {
        $query = EmployeePerformanceScore::query()->with(['employee.user', 'salesPlan']);
        $this->whereEmployee($query, $filters);
        $this->whereMonth($query, $filters, 'salesPlan.month');

        return $query;
    }

    /**
     * @param  array<string, int|string>  $filters
     * @return Builder<EmployeeSalaryCalculation>
     */
    private function salaryQuery(array $filters): Builder
    {
        $query = EmployeeSalaryCalculation::query()->with(['employee.user', 'salesPlan']);
        $this->whereEmployee($query, $filters);
        $this->whereMonth($query, $filters, 'salesPlan.month');

        return $query;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, int|string>  $filters
     */
    private function whereEmployee(Builder $query, array $filters): void
    {
        if (isset($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, int|string>  $filters
     */
    private function whereMonth(Builder $query, array $filters, string $column): void
    {
        if (! isset($filters['month']) || ! is_string($filters['month'])) {
            return;
        }

        if (str_contains($column, '.')) {
            [$relation, $relationColumn] = explode('.', $column, 2);
            $query->whereHas($relation, fn (Builder $related): Builder => $related->whereDate($relationColumn, $filters['month']));

            return;
        }

        $query->whereDate($column, $filters['month']);
    }
}
