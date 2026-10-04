<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EmployeePermission;
use App\Enums\PlanTaskStatus;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\ScopesToSelectedEmployee;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Support\Dashboard\DashboardPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Active employees ranked by tasks completed, then visits made, in the
 * selected window.
 */
final class EmployeesTopPerformers extends TableWidget
{
    protected static bool $isLazy = false;

    use BuildsDashboardTables;
    use ScopesToSelectedEmployee;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(EmployeePermission::EmployeeView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.employees.tables.top_employees'))
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => self::paginateRows(
                $this->rows(),
                $page,
                $recordsPerPage,
            ))
            ->recordUrl(fn (array $record): string => EmployeeResource::getUrl('view', ['record' => $record['employee_id']]))
            ->columns([
                TextColumn::make('name')
                    ->label(__('dashboards.employees.columns.employee'))
                    ->weight('medium'),
                TextColumn::make('tasks_completed')
                    ->label(__('dashboards.employees.columns.tasks_completed'))
                    ->numeric(),
                TextColumn::make('visits')
                    ->label(__('dashboards.employees.columns.visits'))
                    ->numeric(),
            ]);
    }

    /** @return array<int|string, array{employee_id: int, name: string, tasks_completed: int, visits: int}> */
    private function rows(): array
    {
        $period = $this->dashboardPeriod();
        $employeeId = $this->dashboardFilter('employeeId');

        $tasks = PlanTask::query()
            ->join('sales_plans', 'sales_plans.id', '=', 'plan_tasks.sales_plan_id')
            ->where('plan_tasks.status', PlanTaskStatus::Completed->value)
            ->whereBetween('plan_tasks.completed_at', [$period->from, $period->to])
            ->groupBy('sales_plans.employee_id')
            ->selectRaw('sales_plans.employee_id as employee_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'employee_id');

        $visits = $this->scopeVisitsToEmployee(CustomerVisit::query())
            ->whereBetween('planned_at', [$period->from, $period->to])
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'employee_id');

        return EmployeeProfile::query()
            ->where('is_active', true)
            ->when($employeeId, static fn (Builder $query, int $id): Builder => $query->whereKey($id))
            ->with('user:id,name')
            ->get()
            ->map(static fn (EmployeeProfile $employee): array => [
                'employee_id' => $employee->id,
                'name' => (string) ($employee->user->name ?? __('dashboards.fallback.employee', ['id' => $employee->id])),
                'tasks_completed' => DashboardPeriod::toInt($tasks->get($employee->id)),
                'visits' => DashboardPeriod::toInt($visits->get($employee->id)),
            ])
            ->filter(static fn (array $row): bool => $row['tasks_completed'] + $row['visits'] > 0)
            ->sortBy([['tasks_completed', 'desc'], ['visits', 'desc']])
            ->keyBy('employee_id')
            ->all();
    }
}
