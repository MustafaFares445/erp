<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Enums\SalesPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class SalesPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SalesPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Permission::findOrCreate(AccountingPermission::JournalEntryPostFromSource->value, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->rolePermissions() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permissions);
        }
    }

    /** @return array<string, list<string>> */
    private function rolePermissions(): array
    {
        return [
            DashboardRole::SystemAdmin->value => SalesPermission::values(),
            DashboardRole::SalesManager->value => [
                SalesPermission::SalesSettingView->value,
                SalesPermission::PaymentTermView->value,
                SalesPermission::PaymentTermManage->value,
                SalesPermission::PaymentMethodView->value,
                SalesPermission::QuotationView->value,
                SalesPermission::QuotationManage->value,
                SalesPermission::QuotationDecide->value,
                SalesPermission::QuotationConvert->value,
                SalesPermission::OrderView->value,
                SalesPermission::OrderManage->value,
                SalesPermission::OrderCreate->value,
                SalesPermission::OrderConfirm->value,
                SalesPermission::OrderRelease->value,
                SalesPermission::OrderCancel->value,
                SalesPermission::OrderClose->value,
                SalesPermission::DeliveryNoteView->value,
                SalesPermission::InvoiceView->value,
                SalesPermission::InvoiceManage->value,
                SalesPermission::InvoiceIssue->value,
                SalesPermission::InvoiceSend->value,
                SalesPermission::InvoiceConfirmReceipt->value,
                SalesPermission::PaymentView->value,
                SalesPermission::CreditNoteView->value,
                SalesPermission::CreditNoteManage->value,
                SalesPermission::CreditNoteConfirm->value,
                SalesPermission::AuditView->value,
                SalesPermission::ReportView->value,
                SalesPermission::Export->value,
                AccountingPermission::JournalEntryPostFromSource->value,
            ],
            DashboardRole::SalesOfficer->value => [
                SalesPermission::PaymentTermView->value,
                SalesPermission::QuotationView->value,
                SalesPermission::QuotationManage->value,
                SalesPermission::QuotationDecide->value,
                SalesPermission::OrderView->value,
                SalesPermission::OrderManage->value,
                SalesPermission::OrderCreate->value,
                SalesPermission::DeliveryNoteView->value,
                SalesPermission::InvoiceView->value,
                SalesPermission::InvoiceConfirmReceipt->value,
                SalesPermission::ReportView->value,
            ],
            DashboardRole::BillingOfficer->value => [
                SalesPermission::SalesSettingView->value,
                SalesPermission::PaymentTermView->value,
                SalesPermission::PaymentMethodView->value,
                SalesPermission::QuotationView->value,
                SalesPermission::OrderView->value,
                SalesPermission::DeliveryNoteView->value,
                SalesPermission::InvoiceView->value,
                SalesPermission::InvoiceManage->value,
                SalesPermission::InvoiceIssue->value,
                SalesPermission::InvoiceSend->value,
                SalesPermission::InvoiceConfirmReceipt->value,
                SalesPermission::PaymentView->value,
                SalesPermission::PaymentRecord->value,
                SalesPermission::CreditNoteView->value,
                SalesPermission::CreditNoteManage->value,
                SalesPermission::AuditView->value,
                SalesPermission::ReportView->value,
                SalesPermission::Export->value,
                AccountingPermission::JournalEntryPostFromSource->value,
            ],
            DashboardRole::Reviewer->value => [
                SalesPermission::SalesSettingView->value,
                SalesPermission::PaymentTermView->value,
                SalesPermission::PaymentMethodView->value,
                SalesPermission::QuotationView->value,
                SalesPermission::OrderView->value,
                SalesPermission::DeliveryNoteView->value,
                SalesPermission::InvoiceView->value,
                SalesPermission::PaymentView->value,
                SalesPermission::CreditNoteView->value,
                SalesPermission::AuditView->value,
                SalesPermission::ReportView->value,
            ],
        ];
    }
}
