<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PurchasePermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Realises the role matrix in contracts/permissions.md §2.
 *
 * System Admin receives `PurchasePermission::values()` in full rather than an
 * enumerated list, so a permission added to the catalogue later is never
 * silently withheld from the admin role.
 */
final class PurchasePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PurchasePermission::values() as $permission) {
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
            'System Admin' => PurchasePermission::values(),
            'Purchasing Manager' => [
                PurchasePermission::OrderView->value,
                PurchasePermission::OrderManage->value,
                PurchasePermission::OrderSubmit->value,
                PurchasePermission::OrderApprove->value,
                PurchasePermission::OrderSend->value,
                PurchasePermission::OrderCancel->value,
                PurchasePermission::OrderClose->value,
                PurchasePermission::OrderReceive->value,
                PurchasePermission::ConfirmationView->value,
                PurchasePermission::ConfirmationRecord->value,
                PurchasePermission::SupplierView->value,
                PurchasePermission::SupplierManage->value,
                PurchasePermission::ProductReferenceView->value,
                PurchasePermission::ProductReferenceManage->value,
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
                PurchasePermission::OrderReceive->value,
                PurchasePermission::ConfirmationView->value,
                PurchasePermission::ConfirmationRecord->value,
                PurchasePermission::SupplierView->value,
                PurchasePermission::ProductReferenceView->value,
            ],
            'Reviewer' => [
                PurchasePermission::OrderView->value,
                PurchasePermission::ConfirmationView->value,
                PurchasePermission::SupplierView->value,
                PurchasePermission::ProductReferenceView->value,
                PurchasePermission::ReportView->value,
                PurchasePermission::AuditView->value,
            ],
        ];
    }
}
