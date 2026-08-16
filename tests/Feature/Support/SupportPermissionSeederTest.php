<?php

declare(strict_types=1);

use App\Enums\SupportPermission;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('seeds the support catalogue and fixed role mappings on the web guard', function (): void {
    (new SupportPermissionSeeder)->run();

    expect(Permission::query()->where('guard_name', 'web')->pluck('name')->all())
        ->toContain(...SupportPermission::values())
        ->and(Role::findByName('System Admin')->permissions->pluck('name')->all())
        ->toContain(...SupportPermission::values())
        ->and(Role::findByName('Support Manager')->permissions->pluck('name')->all())
        ->toContain(SupportPermission::TicketManage->value, SupportPermission::ServiceRecordManage->value)
        ->not->toContain(SupportPermission::TicketSettlePayment->value, SupportPermission::PartsReverse->value)
        ->and(Role::findByName('Support Agent')->permissions->pluck('name')->all())
        ->toContain(SupportPermission::TicketWork->value, SupportPermission::ServiceRecordExecute->value)
        ->not->toContain(SupportPermission::TicketManage->value, SupportPermission::TicketAssign->value)
        ->and(Role::findByName('Reviewer')->permissions->pluck('name')->all())
        ->toEqualCanonicalizing([
            SupportPermission::TicketView->value,
            SupportPermission::SlaPolicyView->value,
            SupportPermission::MaintenanceRequestView->value,
            SupportPermission::ServiceRecordView->value,
            SupportPermission::ReportView->value,
            SupportPermission::AuditView->value,
        ]);
});

it('is idempotent and preserves unrelated role permissions', function (): void {
    (new SupportPermissionSeeder)->run();

    $unrelatedPermission = Permission::create(['name' => 'crm.customer.view', 'guard_name' => 'web']);
    Role::findByName('Support Manager')->givePermissionTo($unrelatedPermission);

    (new SupportPermissionSeeder)->run();

    expect(Permission::query()->whereIn('name', SupportPermission::values())->count())
        ->toBe(count(SupportPermission::values()))
        ->and(Role::findByName('Support Manager')->hasPermissionTo($unrelatedPermission))->toBeTrue();
});
