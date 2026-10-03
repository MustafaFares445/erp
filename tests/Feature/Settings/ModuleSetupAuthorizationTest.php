<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchasePermission;
use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Resources\PackageTypes\PackageTypeResource;
use App\Filament\Resources\SalesSettings\SalesSettingResource;
use App\Models\Currency;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();
});

function setupUser(DashboardRole $role): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole($role->value);

    return $user;
}

it('lets an accountant manage the currency catalogue without system admin', function (): void {
    $chief = setupUser(DashboardRole::ChiefAccountant);

    expect($chief->can('viewAny', Currency::class))->toBeTrue()
        ->and($chief->can('create', Currency::class))->toBeTrue()
        ->and($chief->hasRole(DashboardRole::SystemAdmin->value))->toBeFalse();
});

it('keeps the currency catalogue closed to inventory and purchasing roles', function (): void {
    $warehouse = setupUser(DashboardRole::WarehouseManager);

    expect($warehouse->can('viewAny', Currency::class))->toBeFalse();

    $this->actingAs($warehouse)->get(CurrencyResource::getUrl())->assertForbidden();
});

it('no longer treats the purchasing setting permission as currency access', function (): void {
    $user = User::factory()->admin()->create();
    $user->givePermissionTo(PurchasePermission::SettingManage->value);

    expect($user->can('viewAny', Currency::class))->toBeFalse();
});

it('keeps accounting-owned and inventory-owned setup separate', function (): void {
    $warehouse = setupUser(DashboardRole::WarehouseManager);
    $chief = setupUser(DashboardRole::ChiefAccountant);

    $this->actingAs($warehouse)->get(SalesSettingResource::getUrl())->assertForbidden();
    $this->actingAs($chief)->get(PackageTypeResource::getUrl())->assertForbidden();
});

it('gives system admin every setup surface', function (): void {
    $admin = setupUser(DashboardRole::SystemAdmin);

    $this->actingAs($admin)->get(CurrencyResource::getUrl())->assertOk();
    $this->actingAs($admin)->get(SalesSettingResource::getUrl())->assertOk();
    $this->actingAs($admin)->get(PackageTypeResource::getUrl())->assertOk();
});
