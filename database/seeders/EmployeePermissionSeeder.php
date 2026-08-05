<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EmployeePermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class EmployeePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (EmployeePermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->rolePermissions() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permissions);
        }
    }

    /** @return array<string, list<string>> */
    private function rolePermissions(): array
    {
        return [
            'System Admin' => EmployeePermission::values(),
            'Employee Manager' => [
                EmployeePermission::EmployeeView->value,
                EmployeePermission::EmployeeManage->value,
                EmployeePermission::PlanView->value,
                EmployeePermission::PlanManage->value,
                EmployeePermission::TaskView->value,
                EmployeePermission::TaskManage->value,
                EmployeePermission::VisitView->value,
                EmployeePermission::VisitReview->value,
                EmployeePermission::VoiceNoteView->value,
                EmployeePermission::VoiceNotePlay->value,
                EmployeePermission::AiRuleView->value,
                EmployeePermission::OpportunityView->value,
                EmployeePermission::PerformanceView->value,
                EmployeePermission::ReportView->value,
                EmployeePermission::AuditView->value,
            ],
            'Payroll Officer' => [
                EmployeePermission::EmployeeView->value,
                EmployeePermission::PlanView->value,
                EmployeePermission::PerformanceView->value,
                EmployeePermission::PerformanceRecalculate->value,
                EmployeePermission::SalaryView->value,
                EmployeePermission::SalaryCalculate->value,
                EmployeePermission::SalaryConfirm->value,
                EmployeePermission::BonusView->value,
                EmployeePermission::BonusApprove->value,
                EmployeePermission::ReportView->value,
                EmployeePermission::AuditView->value,
            ],
            'Reviewer' => [
                EmployeePermission::EmployeeView->value,
                EmployeePermission::PlanView->value,
                EmployeePermission::TaskView->value,
                EmployeePermission::VisitView->value,
                EmployeePermission::VoiceNoteView->value,
                EmployeePermission::AiRuleView->value,
                EmployeePermission::OpportunityView->value,
                EmployeePermission::PerformanceView->value,
                EmployeePermission::SalaryView->value,
                EmployeePermission::BonusView->value,
                EmployeePermission::ReportView->value,
                EmployeePermission::AuditView->value,
            ],
        ];
    }
}
