<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PurchasePermission;
use Database\Seeders\Concerns\SeedsPermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Realises the role matrix in contracts/permissions.md §2.
 *
 * System Admin receives `PurchasePermission::values()` in full rather than an
 * enumerated list, so a permission added to the catalogue later is never
 * silently withheld from the admin role.
 */
final class PurchasePermissionSeeder extends Seeder
{
    use SeedsPermissionCatalog;

    public function run(): void
    {
        $this->seedPermissionCatalog(PurchasePermission::values());

        foreach ($this->rolePermissions() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permissions);
        }
    }

    /** @return array<string, list<string>> */
    private function rolePermissions(): array
    {
        return [
            'System Admin' => PurchasePermission::values(),
            'Purchasing Manager' => [
                PurchasePermission::OrderView->value,
                PurchasePermission::OrderManage->value,
                PurchasePermission::OrderSubmit->value,
                PurchasePermission::OrderApprove->value,
                PurchasePermission::OrderSend->value,
                PurchasePermission::OrderCancel->value,
                PurchasePermission::OrderClose->value,
                PurchasePermission::ConfirmationView->value,
                PurchasePermission::ConfirmationRecord->value,
                PurchasePermission::SupplierView->value,
                PurchasePermission::SupplierManage->value,
                PurchasePermission::ProductReferenceView->value,
                PurchasePermission::ProductReferenceManage->value,
                PurchasePermission::RfqView->value,
                PurchasePermission::RfqManage->value,
                PurchasePermission::RfqAward->value,
                PurchasePermission::AgreementView->value,
                PurchasePermission::AgreementManage->value,
                PurchasePermission::ReportView->value,
                PurchasePermission::AuditView->value,
            ],
            // No approve, send, cancel, or close: an officer drafts and submits,
            // and someone else commits the money. That separation is the whole
            // purpose of the threshold gate (R-A).
            'Purchasing Officer' => [
                PurchasePermission::OrderView->value,
                PurchasePermission::OrderManage->value,
                PurchasePermission::OrderSubmit->value,
                PurchasePermission::ConfirmationView->value,
                PurchasePermission::ConfirmationRecord->value,
                PurchasePermission::SupplierView->value,
                PurchasePermission::ProductReferenceView->value,
                PurchasePermission::RfqView->value,
                PurchasePermission::RfqManage->value,
                PurchasePermission::AgreementView->value,
            ],
            'Reviewer' => [
                PurchasePermission::OrderView->value,
                PurchasePermission::ConfirmationView->value,
                PurchasePermission::SupplierView->value,
                PurchasePermission::ProductReferenceView->value,
                PurchasePermission::RfqView->value,
                PurchasePermission::AgreementView->value,
                PurchasePermission::ReportView->value,
                PurchasePermission::AuditView->value,
            ],
        ];
    }
}
