<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the `accounting.*` catalogue and its role assignments.
 *
 * Idempotent — `findOrCreate` and `givePermissionTo` are both safe to repeat.
 *
 * @see /specs/018-chart-of-accounts-journals/contracts/permissions.md §5
 */
final class AccountingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (AccountingPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->rolePermissions() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permissions);
        }
    }

    /**
     * The matrix from contracts/permissions.md §2.
     *
     * `Accountant` deliberately lacks `JournalEntryReverse` and
     * `FiscalPeriodClose`: recording and posting are day-to-day work, while
     * correcting already-reported history and declaring a period final are not
     * (FR-040).
     *
     * @return array<string, list<string>>
     */
    private function rolePermissions(): array
    {
        return [
            DashboardRole::SystemAdmin->value => AccountingPermission::values(),
            DashboardRole::ChiefAccountant->value => AccountingPermission::values(),
            DashboardRole::Accountant->value => [
                AccountingPermission::ChartAccountView->value,
                AccountingPermission::ChartAccountManage->value,
                AccountingPermission::FiscalPeriodView->value,
                AccountingPermission::JournalEntryView->value,
                AccountingPermission::JournalEntryManage->value,
                AccountingPermission::JournalEntryPost->value,
                AccountingPermission::LedgerView->value,
            ],
            DashboardRole::Reviewer->value => [
                AccountingPermission::ChartAccountView->value,
                AccountingPermission::FiscalPeriodView->value,
                AccountingPermission::JournalEntryView->value,
                AccountingPermission::LedgerView->value,
                AccountingPermission::AuditView->value,
            ],
        ];
    }
}
