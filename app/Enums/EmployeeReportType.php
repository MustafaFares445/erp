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
            self::PlanCompletion => 'Plan Completion',
            self::OverdueTasks => 'Overdue Tasks',
            self::UnexecutedVisits => 'Unexecuted Visits',
            self::PerformanceByEmployee => 'Performance by Employee',
            self::PerformanceByMonth => 'Performance by Month',
            self::SalaryByEmployee => 'Salary by Employee',
            self::SalaryByMonth => 'Salary by Month',
        };
    }
}
