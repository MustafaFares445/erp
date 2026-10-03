<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\SalesOpportunity;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrows Employees-dashboard queries to the employee picked in the filter
 * bar. A task belongs to its sales plan's employee; an opportunity to the
 * employee's user as owner.
 *
 * @phpstan-require-extends Widget
 */
trait ScopesToSelectedEmployee
{
    use InteractsWithDashboardFilters;

    /**
     * @param  Builder<PlanTask>  $query
     * @return Builder<PlanTask>
     */
    protected function scopeTasksToEmployee(Builder $query): Builder
    {
        return $query->when(
            $this->dashboardFilter('employeeId'),
            static fn (Builder $query, int $employeeId): Builder => $query->whereHas('salesPlan', static fn (Builder $plan): Builder => $plan->where('employee_id', $employeeId)),
        );
    }

    /**
     * @param  Builder<CustomerVisit>  $query
     * @return Builder<CustomerVisit>
     */
    protected function scopeVisitsToEmployee(Builder $query): Builder
    {
        return $query->when(
            $this->dashboardFilter('employeeId'),
            static fn (Builder $query, int $employeeId): Builder => $query->where('employee_id', $employeeId),
        );
    }

    /**
     * @param  Builder<SalesOpportunity>  $query
     * @return Builder<SalesOpportunity>
     */
    protected function scopeOpportunitiesToEmployee(Builder $query): Builder
    {
        return $query->when(
            $this->dashboardFilter('employeeId'),
            static fn (Builder $query, int $employeeId): Builder => $query->where(
                'owner_id',
                EmployeeProfile::query()->whereKey($employeeId)->value('user_id'),
            ),
        );
    }
}
