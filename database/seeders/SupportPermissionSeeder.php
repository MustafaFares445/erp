<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SupportPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class SupportPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SupportPermission::values() as $permission) {
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
            'System Admin' => SupportPermission::values(),
            'Support Manager' => [
                SupportPermission::TicketView->value,
                SupportPermission::TicketManage->value,
                SupportPermission::TicketAssign->value,
                SupportPermission::TicketMessage->value,
                SupportPermission::SlaPolicyView->value,
                SupportPermission::SlaPolicyManage->value,
                SupportPermission::MaintenanceRequestView->value,
                SupportPermission::MaintenanceRequestManage->value,
                SupportPermission::ServiceRecordView->value,
                SupportPermission::ServiceRecordManage->value,
                SupportPermission::PartsConsume->value,
                SupportPermission::ReportView->value,
                SupportPermission::AuditView->value,
            ],
            'Support Agent' => [
                SupportPermission::TicketView->value,
                SupportPermission::TicketWork->value,
                SupportPermission::TicketMessage->value,
                SupportPermission::MaintenanceRequestView->value,
                SupportPermission::ServiceRecordView->value,
                SupportPermission::ServiceRecordExecute->value,
                SupportPermission::PartsConsume->value,
            ],
            'Reviewer' => [
                SupportPermission::TicketView->value,
                SupportPermission::SlaPolicyView->value,
                SupportPermission::MaintenanceRequestView->value,
                SupportPermission::ServiceRecordView->value,
                SupportPermission::ReportView->value,
                SupportPermission::AuditView->value,
            ],
        ];
    }
}
