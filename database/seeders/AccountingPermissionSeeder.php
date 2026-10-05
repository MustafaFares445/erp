<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use Database\Seeders\Concerns\SeedsPermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

final class AccountingPermissionSeeder extends Seeder
{
    use SeedsPermissionCatalog;

    public function run(): void
    {
        $this->seedPermissionCatalog(AccountingPermission::values());

        foreach ($this->rolePermissions() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permissions);
        }
    }

    /** @return array<string, list<string>> */
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
                AccountingPermission::JournalEntryPostFromSource->value,
                AccountingPermission::BankReconciliationView->value,
                AccountingPermission::BankReconciliationManage->value,
                AccountingPermission::LedgerView->value,
                AccountingPermission::ReportView->value,
                AccountingPermission::ReceivableView->value,
                AccountingPermission::PayableView->value,
                AccountingPermission::BillView->value,
                AccountingPermission::BillManage->value,
                AccountingPermission::ExpenseView->value,
                AccountingPermission::ExpenseManage->value,
                AccountingPermission::SupplierPaymentManage->value,
                AccountingPermission::RefundView->value,
                AccountingPermission::RefundManage->value,
                AccountingPermission::WriteOffRecord->value,
                AccountingPermission::TaxView->value,
            ],
            DashboardRole::Reviewer->value => [
                AccountingPermission::ChartAccountView->value,
                AccountingPermission::FiscalPeriodView->value,
                AccountingPermission::JournalEntryView->value,
                AccountingPermission::BankReconciliationView->value,
                AccountingPermission::LedgerView->value,
                AccountingPermission::AuditView->value,
                AccountingPermission::ReportView->value,
                AccountingPermission::ReceivableView->value,
                AccountingPermission::PayableView->value,
                AccountingPermission::BillView->value,
                AccountingPermission::ExpenseView->value,
                AccountingPermission::RefundView->value,
                AccountingPermission::TaxView->value,
            ],
        ];
    }
}
