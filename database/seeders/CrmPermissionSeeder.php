<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CrmPermission;
use App\Enums\InventoryPermission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class CrmPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach ($this->rolePermissions() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permissions);
        }

        $administrator = User::query()->where('email', 'admin@ierp.com')->first();

        if ($administrator instanceof User) {
            $administrator->assignRole('System Admin');
        }
    }

    /** @return list<string> */
    private function permissions(): array
    {
        return [...CrmPermission::values(), InventoryPermission::PriceFloorApprove->value];
    }

    /** @return array<string, list<string>> */
    private function rolePermissions(): array
    {
        return [
            'System Admin' => $this->permissions(),
            'CRM Manager' => [
                CrmPermission::CustomerView->value,
                CrmPermission::CustomerManage->value,
                CrmPermission::SubscriptionView->value,
                CrmPermission::SubscriptionManage->value,
                CrmPermission::SubscriptionDiscountManage->value,
                CrmPermission::SubscriptionLinkManage->value,
                CrmPermission::PricePreview->value,
                CrmPermission::ReportView->value,
                CrmPermission::AuditView->value,
            ],
            'Pricing Manager' => [
                CrmPermission::CustomerView->value,
                CrmPermission::SubscriptionView->value,
                CrmPermission::SubscriptionDiscountManage->value,
                CrmPermission::PricePreview->value,
                CrmPermission::ReportView->value,
                CrmPermission::AuditView->value,
            ],
            'Reviewer' => [
                CrmPermission::CustomerView->value,
                CrmPermission::SubscriptionView->value,
                CrmPermission::PricePreview->value,
                CrmPermission::ReportView->value,
                CrmPermission::AuditView->value,
            ],
        ];
    }
}
