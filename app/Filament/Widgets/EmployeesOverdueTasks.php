<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EmployeePermission;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\ScopesToSelectedEmployee;
use App\Models\PlanTask;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Open tasks past their due date, most overdue first. A current-state work
 * queue, so it ignores the date range but respects the employee filter.
 */
final class EmployeesOverdueTasks extends TableWidget
{
    use BuildsDashboardTables;
    use ScopesToSelectedEmployee;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(EmployeePermission::TaskView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.employees.tables.overdue_tasks'))
            ->query(fn (): Builder => $this->scopeTasksToEmployee(PlanTask::query())
                ->overdue()
                ->with(['customer:id,company_name', 'salesPlan.employee.user:id,name'])
                ->orderBy('due_at'))
            ->recordUrl(fn (PlanTask $record): string => TaskResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('title')
                    ->label(__('dashboards.employees.columns.task'))
                    ->limit(40)
                    ->description(fn (PlanTask $record): ?string => $record->customer?->company_name)
                    ->weight('medium'),
                TextColumn::make('salesPlan.employee.user.name')
                    ->label(__('dashboards.employees.columns.employee'))
                    ->placeholder('—'),
                TextColumn::make('due_at')
                    ->label(__('dashboards.employees.columns.due'))
                    ->date()
                    ->color('danger'),
                TextColumn::make('status')
                    ->label(__('dashboards.employees.columns.status'))
                    ->badge(),
            ]);
    }
}
