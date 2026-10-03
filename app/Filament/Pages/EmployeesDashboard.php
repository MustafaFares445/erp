<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\EmployeePermission;
use App\Filament\Widgets\EmployeesOverdueTasks;
use App\Filament\Widgets\EmployeesStatistics;
use App\Filament\Widgets\EmployeesTaskStatusChart;
use App\Filament\Widgets\EmployeesTaskTrend;
use App\Filament\Widgets\EmployeesTopPerformers;
use App\Models\EmployeeProfile;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

/**
 * Employees' module landing page: field-work KPIs, task completion beside
 * task status, then the top performers beside the overdue-task queue — all
 * narrowable to one employee.
 */
final class EmployeesDashboard extends ModuleDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    #[\Override]
    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user?->can(EmployeePermission::EmployeeView->value) ?? false) {
            return true;
        }

        return (bool) ($user?->can(EmployeePermission::TaskView->value) ?? false);
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.employees_dashboard');
    }

    /** @return array<Select> */
    #[\Override]
    protected function moduleFilters(): array
    {
        return [
            Select::make('employeeId')
                ->label(__('dashboards.employees.filters.employee'))
                ->searchable()
                ->native(false)
                ->options(fn (): array => EmployeeProfile::query()
                    ->where('is_active', true)
                    ->with('user:id,name')
                    ->get()
                    ->mapWithKeys(static fn (EmployeeProfile $employee): array => [$employee->id => (string) $employee->user?->name])
                    ->all()),
        ];
    }

    #[\Override]
    protected function getDashboardWidgets(): array
    {
        return [
            EmployeesStatistics::class,
            [EmployeesTaskTrend::class, EmployeesTaskStatusChart::class],
            [EmployeesTopPerformers::class, EmployeesOverdueTasks::class],
        ];
    }
}
