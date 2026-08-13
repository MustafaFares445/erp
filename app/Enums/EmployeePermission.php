<?php

declare(strict_types=1);

namespace App\Enums;

use App\Policies\Concerns\ChecksEmployeePermissions;
use Database\Seeders\EmployeePermissionSeeder;

/**
 * Canonical `employees.*` permission catalogue (guard: `web`).
 *
 * Single source of truth consumed by {@see EmployeePermissionSeeder}
 * and by {@see ChecksEmployeePermissions} so the dashboard and
 * any other access channel share identical permission names.
 *
 * @see /specs/015-employees-plans-visits-dashboard/contracts/permissions.md
 */
enum EmployeePermission: string
{
    case EmployeeView = 'employees.employee.view';
    case EmployeeManage = 'employees.employee.manage';
    case EmployeeRestore = 'employees.employee.restore';
    case PlanView = 'employees.plan.view';
    case PlanManage = 'employees.plan.manage';
    case PlanRestore = 'employees.plan.restore';
    case TaskView = 'employees.task.view';
    case TaskManage = 'employees.task.manage';
    case VisitView = 'employees.visit.view';
    case VisitReview = 'employees.visit.review';
    case VoiceNoteView = 'employees.voice-note.view';
    case VoiceNotePlay = 'employees.voice-note.play';
    case AiRuleView = 'employees.ai-rule.view';
    case AiRuleManage = 'employees.ai-rule.manage';
    case OpportunityView = 'employees.opportunity.view';
    case OpportunityReview = 'employees.opportunity.review';
    case PerformanceView = 'employees.performance.view';
    case PerformanceRecalculate = 'employees.performance.recalculate';
    case SalaryView = 'employees.salary.view';
    case SalaryCalculate = 'employees.salary.calculate';
    case SalaryConfirm = 'employees.salary.confirm';
    case BonusView = 'employees.bonus.view';
    case BonusApprove = 'employees.bonus.approve';
    case ReportView = 'employees.report.view';
    case AuditView = 'employees.audit.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
