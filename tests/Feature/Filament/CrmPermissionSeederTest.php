<?php

declare(strict_types=1);

use App\Enums\CrmPermission;
use App\Enums\InventoryPermission;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('seeds the CRM catalogue and fixed role mappings on the web guard', function (): void {
    (new CrmPermissionSeeder)->run();

    expect(Permission::query()->where('guard_name', 'web')->pluck('name')->all())
        ->toContain(...CrmPermission::values())
        ->toContain(InventoryPermission::PriceFloorApprove->value)
        ->and(Role::findByName('System Admin')->permissions->pluck('name')->all())
        ->toContain(...CrmPermission::values())
        ->toContain(InventoryPermission::PriceFloorApprove->value)
        ->and(Role::findByName('CRM Manager')->permissions->pluck('name')->all())
        ->toContain(CrmPermission::CustomerManage->value, CrmPermission::PricingTierLinkManage->value)
        ->not->toContain(CrmPermission::CustomerRestore->value, CrmPermission::DashboardRoleAssign->value)
        ->and(Role::findByName('Pricing Manager')->permissions->pluck('name')->all())
        ->toContain(CrmPermission::PricingTierDiscountManage->value, CrmPermission::PricePreview->value)
        ->not->toContain(CrmPermission::CustomerManage->value, CrmPermission::PricingTierLinkManage->value)
        ->and(Role::findByName('Reviewer')->permissions->pluck('name')->all())
        ->toEqualCanonicalizing([
            CrmPermission::CustomerView->value,
            CrmPermission::LeadView->value,
            CrmPermission::InteractionView->value,
            CrmPermission::CampaignView->value,
            CrmPermission::FunnelReport->value,
            CrmPermission::PricingTierView->value,
            CrmPermission::PricePreview->value,
            CrmPermission::ReportView->value,
            CrmPermission::AuditView->value,
        ]);
});

it('is idempotent and preserves unrelated role permissions', function (): void {
    $user = User::factory()->create(['email' => 'admin@ierp.com']);
    $unrelatedPermission = Permission::create(['name' => 'inventory.warehouse.view', 'guard_name' => 'web']);

    (new CrmPermissionSeeder)->run();
    Role::findByName('CRM Manager')->givePermissionTo($unrelatedPermission);
    (new CrmPermissionSeeder)->run();

    expect(Permission::query()->whereIn('name', CrmPermission::values())->count())
        ->toBe(count(CrmPermission::values()))
        ->and(Role::findByName('CRM Manager')->hasPermissionTo($unrelatedPermission))->toBeTrue()
        ->and($user->fresh()->hasRole('System Admin'))->toBeTrue();
});
