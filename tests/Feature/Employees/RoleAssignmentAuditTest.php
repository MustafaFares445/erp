<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Identity\DashboardRoleAssignmentService;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new CrmPermissionSeeder)->run();
    (new EmployeePermissionSeeder)->run();
});

it('assigns the Employee Manager fixed role and audits the change', function (): void {
    $systemAdmin = User::factory()->admin()->create();
    $systemAdmin->assignRole('System Admin');

    $target = User::factory()->admin()->create();

    app(DashboardRoleAssignmentService::class)->assign($target, 'Employee Manager', $systemAdmin);

    expect($target->fresh()->getRoleNames()->all())->toBe(['Employee Manager'])
        ->and(AuditLog::query()->where('action', 'identity.dashboard_roles.assigned')->where('entity_id', $target->id)->exists())->toBeTrue();
});

it('assigns the Payroll Officer fixed role and audits the change', function (): void {
    $systemAdmin = User::factory()->admin()->create();
    $systemAdmin->assignRole('System Admin');

    $target = User::factory()->admin()->create();

    app(DashboardRoleAssignmentService::class)->assign($target, 'Payroll Officer', $systemAdmin);

    expect($target->fresh()->getRoleNames()->all())->toBe(['Payroll Officer'])
        ->and(AuditLog::query()->where('action', 'identity.dashboard_roles.assigned')->where('entity_id', $target->id)->exists())->toBeTrue();
});

it('audits a role change from one fixed role to another', function (): void {
    $systemAdmin = User::factory()->admin()->create();
    $systemAdmin->assignRole('System Admin');

    $target = User::factory()->admin()->create();
    $target->assignRole('Employee Manager');

    app(DashboardRoleAssignmentService::class)->assign($target, 'Payroll Officer', $systemAdmin);

    $entry = AuditLog::query()->where('action', 'identity.dashboard_roles.assigned')->where('entity_id', $target->id)->latest('id')->first();

    expect($target->fresh()->getRoleNames()->all())->toBe(['Payroll Officer'])
        ->and($entry->old_values['roles'])->toBe(['Employee Manager'])
        ->and($entry->new_values['roles'])->toBe(['Payroll Officer']);
});
