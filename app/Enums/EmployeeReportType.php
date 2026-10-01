<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Employees\EmployeeReportService;

/**
 * The seven employee report aggregates (FR-071, FR-072), served by
 * {@see EmployeeReportService}.
 */
enum EmployeeReportType: string
{
    case PlanCompletion = 'PlanCompletion';
    case OverdueTasks = 'OverdueTasks';
    case UnexecutedVisits = 'UnexecutedVisits';
    case PerformanceByEmployee = 'PerformanceByEmployee';
    case PerformanceByMonth = 'PerformanceByMonth';
    case SalaryByEmployee = 'SalaryByEmployee';
    case SalaryByMonth = 'SalaryByMonth';

    public function label(): string
    {
        return match ($this) {
            self::PlanCompletion => __(__('Plan Completion')),
            self::OverdueTasks => __(__('Overdue Tasks')),
            self::UnexecutedVisits => __(__('Unexecuted Visits')),
            self::PerformanceByEmployee => __(__('Performance by Employee')),
            self::PerformanceByMonth => __(__('Performance by Month')),
            self::SalaryByEmployee => __(__('Salary by Employee')),
            self::SalaryByMonth => __(__('Salary by Month')),
        };
    }
}
