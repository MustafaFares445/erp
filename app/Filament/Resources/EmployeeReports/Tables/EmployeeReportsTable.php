<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeReports\Tables;

use App\Enums\EmployeeReportType;
use App\Models\SalesPlan;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class EmployeeReportsTable
{
    public static function configure(Table $table, EmployeeReportType $type): Table
    {
        return $table
            ->columns(self::columns($type))
            ->filters(EmployeeReportFilters::make())
            ->defaultSort('id', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }

    /** @return list<TextColumn> */
    private static function columns(EmployeeReportType $type): array
    {
        return match ($type) {
            EmployeeReportType::PlanCompletion => self::planCompletionColumns(),
            EmployeeReportType::OverdueTasks => self::overdueTaskColumns(),
            EmployeeReportType::UnexecutedVisits => self::unexecutedVisitColumns(),
            EmployeeReportType::PerformanceByEmployee, EmployeeReportType::PerformanceByMonth => self::performanceColumns(),
            EmployeeReportType::SalaryByEmployee, EmployeeReportType::SalaryByMonth => self::salaryColumns(),
        };
    }

    /** @return list<TextColumn> */
    private static function planCompletionColumns(): array
    {
        return [
            TextColumn::make('name')->label('Plan')->searchable(),
            TextColumn::make('employee.user.name')->label('Employee'),
            TextColumn::make('month')->date('Y-m')->sortable(),
            TextColumn::make('tasks_count')->label('Total tasks')->counts('tasks')->numeric(),
            TextColumn::make('completion')
                ->label('Completion %')
                ->state(static fn (SalesPlan $record): float => $record->tasks->count() === 0
                    ? 0.0
                    : round($record->tasks->where('status', 'Completed')->count() / $record->tasks->count() * 100, 2))
                ->suffix('%'),
        ];
    }

    /** @return list<TextColumn> */
    private static function overdueTaskColumns(): array
    {
        return [
            TextColumn::make('title'),
            TextColumn::make('salesPlan.name')->label('Plan'),
            TextColumn::make('salesPlan.employee.user.name')->label('Employee'),
            TextColumn::make('due_at')->date()->sortable(),
            TextColumn::make('status')->badge(),
        ];
    }

    /** @return list<TextColumn> */
    private static function unexecutedVisitColumns(): array
    {
        return [
            TextColumn::make('employee.user.name')->label('Employee'),
            TextColumn::make('customer.company_name')->label('Customer')->placeholder('—'),
            TextColumn::make('status')->badge(),
            TextColumn::make('planned_at')->dateTime()->placeholder('—')->sortable(),
        ];
    }

    /** @return list<TextColumn> */
    private static function performanceColumns(): array
    {
        return [
            TextColumn::make('employee.user.name')->label('Employee'),
            TextColumn::make('salesPlan.name')->label('Plan'),
            TextColumn::make('salesPlan.month')->label('Month')->date('Y-m')->sortable(),
            TextColumn::make('total_score')->label('Total score')->suffix('%')->sortable(),
            TextColumn::make('task_completion_percent')->label('Task completion')->suffix('%'),
            TextColumn::make('calculated_at')->dateTime()->sortable(),
        ];
    }

    /** @return list<TextColumn> */
    private static function salaryColumns(): array
    {
        return [
            TextColumn::make('employee.user.name')->label('Employee'),
            TextColumn::make('salesPlan.name')->label('Plan'),
            TextColumn::make('salesPlan.month')->label('Month')->date('Y-m')->sortable(),
            TextColumn::make('payable_base')->money('AED'),
            TextColumn::make('performance_percent')->suffix('%'),
            TextColumn::make('bonus_amount')->money('AED'),
            TextColumn::make('final_salary')->money('AED')->sortable(),
            TextColumn::make('status')->badge(),
        ];
    }
}
