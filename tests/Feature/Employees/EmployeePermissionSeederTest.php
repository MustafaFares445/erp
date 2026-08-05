<?php

declare(strict_types=1);

use App\Enums\EmployeePermission;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('seeds the employees catalogue and fixed role mappings on the web guard', function (): void {
    (new EmployeePermissionSeeder)->run();

    expect(Permission::query()->where('guard_name', 'web')->pluck('name')->all())
        ->toContain(...EmployeePermission::values())
        ->and(Role::findByName('System Admin')->permissions->pluck('name')->all())
        ->toContain(...EmployeePermission::values())
        ->and(Role::findByName('Employee Manager')->permissions->pluck('name')->all())
        ->toContain(EmployeePermission::EmployeeManage->value, EmployeePermission::VisitReview->value)
        ->not->toContain(EmployeePermission::SalaryConfirm->value, EmployeePermission::BonusApprove->value)
        ->and(Role::findByName('Payroll Officer')->permissions->pluck('name')->all())
        ->toContain(EmployeePermission::SalaryConfirm->value, EmployeePermission::BonusApprove->value)
        ->not->toContain(EmployeePermission::EmployeeManage->value, EmployeePermission::PlanManage->value)
        ->and(Role::findByName('Reviewer')->permissions->pluck('name')->all())
        ->toEqualCanonicalizing([
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
        ]);
});

it('is idempotent and preserves unrelated role permissions', function (): void {
    (new EmployeePermissionSeeder)->run();

    $unrelatedPermission = Permission::create(['name' => 'crm.customer.view', 'guard_name' => 'web']);
    Role::findByName('Employee Manager')->givePermissionTo($unrelatedPermission);

    (new EmployeePermissionSeeder)->run();

    expect(Permission::query()->whereIn('name', EmployeePermission::values())->count())
        ->toBe(count(EmployeePermission::values()))
        ->and(Role::findByName('Employee Manager')->hasPermissionTo($unrelatedPermission))->toBeTrue();
});
