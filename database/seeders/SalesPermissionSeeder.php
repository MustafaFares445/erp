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

/**
 * Realises the role matrix in
 * specs/019-sales-lifecycle-payments-credits/contracts/permissions.md §2.
 *
 * System Admin receives {@see SalesPermission::values()} in full rather than an
 * enumerated list, so a permission added to the catalogue later is never
 * silently withheld from the admin role.
 *
 * Sales Manager and Billing Officer additionally receive exactly one
 * accounting permission each,
 * {@see AccountingPermission::JournalEntryPostFromSource}, and nothing else
 * from that catalogue — see permissions.md §4 for why granting the ordinary
 * `JournalEntryManage`/`JournalEntryPost` pair instead would have let a Sales
 * role reach the Journal Entries dashboard page's free-form manual-entry
 * creation.
 */
final class SalesPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SalesPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Idempotent even if AccountingPermissionSeeder has not yet run in this
        // process — the permission this feature depends on either already
        // exists (018's seeder ran first) or is created here.
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
            // Owns the customer relationship: quotes, converts, prices orders,
            // confirms credit notes. No payment ability at all — the person who
            // agrees a discount is not the person who says the money arrived.
            DashboardRole::SalesManager->value => [
                SalesPermission::SalesSettingView->value,
                SalesPermission::PaymentTermView->value,
                SalesPermission::PaymentTermManage->value,
                SalesPermission::PaymentMethodView->value,
                SalesPermission::QuotationView->value,
                SalesPermission::QuotationManage->value,
                SalesPermission::QuotationDecide->value,
                SalesPermission::QuotationConvert->value,
                SalesPermission::SupplierConfirmationRequest->value,
                SalesPermission::OrderView->value,
                SalesPermission::OrderManage->value,
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
                AccountingPermission::JournalEntryPostFromSource->value,
            ],
            // Works the front end only: quotes, records the customer's answer,
            // confirms delivery receipt. Touches no invoice and no money.
            DashboardRole::SalesOfficer->value => [
                SalesPermission::PaymentTermView->value,
                SalesPermission::QuotationView->value,
                SalesPermission::QuotationManage->value,
                SalesPermission::QuotationDecide->value,
                SalesPermission::SupplierConfirmationRequest->value,
                SalesPermission::OrderView->value,
                SalesPermission::DeliveryNoteView->value,
                SalesPermission::InvoiceView->value,
                SalesPermission::InvoiceConfirmReceipt->value,
            ],
            // Owns the money: issues and sends invoices, records payments, drafts
            // credit notes. No conversion, no credit-note confirmation, and no
            // reversal of anything — drafting a correction and approving it are
            // split.
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
            ],
        ];
    }
}
