<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Enums\FinancialReportType;
use App\Filament\Resources\FinancialReports\FinancialReportResource;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
});

it('opens the page for a System Admin, Chief Accountant, Accountant, and Reviewer, each seeing every report type (SC-008)', function (DashboardRole $role): void {
    $user = User::factory()->admin()->create();
    $user->assignRole($role->value);

    $response = $this->actingAs($user)->get(FinancialReportResource::getUrl());

    $response->assertOk();

    foreach (FinancialReportType::cases() as $type) {
        $response->assertSee(__('admin.accounting.report_type.'.$type->value, [], 'ar'));
    }
})->with([
    'System Admin' => [DashboardRole::SystemAdmin],
    'Chief Accountant' => [DashboardRole::ChiefAccountant],
    'Accountant' => [DashboardRole::Accountant],
    'Reviewer' => [DashboardRole::Reviewer],
]);

it('refuses access and hides the navigation link for a user without report.view (FR-004, SC-008)', function (): void {
    // Holds two other accounting permissions directly but no fixed role — a
    // fixed role would carry accounting.report.view along with it, which
    // would defeat the point of this scenario.
    $user = User::factory()->admin()->create();
    $user->givePermissionTo(AccountingPermission::JournalEntryPost->value, AccountingPermission::LedgerView->value);

    $this->actingAs($user)
        ->get(FinancialReportResource::getUrl())
        ->assertForbidden();

    expect(FinancialReportResource::canAccess())->toBeFalse();
});

it('offers the Reviewer no action that changes a record (FR-004)', function (): void {
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole(DashboardRole::Reviewer->value);

    $this->actingAs($reviewer)
        ->get(FinancialReportResource::getUrl())
        ->assertOk();

    expect(FinancialReportResource::canCreate())->toBeFalse();
});

it('keeps the accounting permission catalogue complete after bank reconciliation was added', function (): void {
    expect(AccountingPermission::values())->toHaveCount(32)
        ->and(AccountingPermission::ReportView->value)->toBe('accounting.report.view')
        ->and(AccountingPermission::BankReconciliationView->value)->toBe('accounting.bank-reconciliation.view')
        ->and(AccountingPermission::BankReconciliationManage->value)->toBe('accounting.bank-reconciliation.manage');
});

it('seeds accounting.report.view idempotently with no duplicate rows (FR-006, FR-007)', function (): void {
    (new AccountingPermissionSeeder)->run();

    $permissionCount = Permission::query()->where('name', AccountingPermission::ReportView->value)->count();
    $accountantGrantCount = Role::findByName(DashboardRole::Accountant->value, 'web')
        ->permissions()
        ->where('name', AccountingPermission::ReportView->value)
        ->count();

    expect($permissionCount)->toBe(1)
        ->and($accountantGrantCount)->toBe(1);
});
