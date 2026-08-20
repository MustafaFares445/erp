<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryOperation;
use App\Models\PricingTier;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Two separate guarantees live in this file.
 *
 * The first is FR-008: a purchasing role receives against a purchase order
 * without holding a single `inventory.*` permission. If receiving had been
 * built on the inventory catalogue, this would be impossible to satisfy, so
 * the test is the check on that design decision (R-006).
 *
 * The second is the cross-module consequence of adding two cases to
 * `DashboardRole`. Every module's `isAdmin() && ! hasAnyRole(fixedRoleNames())`
 * check narrows when the enum grows. That is the enum's documented purpose, but
 * it is a real behavioural change to shipped code, so it is proven rather than
 * assumed.
 */

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
});

function purchasingRoleUser(DashboardRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

it('lets a purchasing role receive without granting any inventory permission (FR-008)', function (DashboardRole $role): void {
    $user = purchasingRoleUser($role);
    $order = PurchaseOrder::factory()->sent()->create();

    expect($user->can('receive', $order))->toBeTrue();

    foreach (InventoryPermission::values() as $permission) {
        expect($user->can($permission))->toBeFalse($permission);
    }
})->with([
    'purchasing manager' => [DashboardRole::PurchasingManager],
    'purchasing officer' => [DashboardRole::PurchasingOfficer],
]);

it('keeps a purchasing role out of the Inventory Operations surface', function (): void {
    // The receiving flow opens an inventory operation on the user's behalf, but
    // the operation *resource* stays closed to them. Being able to receive
    // against your own purchase order is not the same privilege as being able
    // to browse and adjust every operation in the warehouse.
    $officer = purchasingRoleUser(DashboardRole::PurchasingOfficer);

    expect($officer->can('viewAny', InventoryOperation::class))->toBeFalse();
});

it('keeps a purchasing role out of every other module', function (DashboardRole $role): void {
    (new AccountingPermissionSeeder)->run();

    $user = purchasingRoleUser($role);

    expect($user->can('viewAny', Ticket::class))->toBeFalse()
        ->and($user->can('viewAny', CustomerProfile::class))->toBeFalse()
        ->and($user->can('viewAny', EmployeeProfile::class))->toBeFalse()
        ->and($user->can('viewAny', PricingTier::class))->toBeFalse()
        ->and($user->can('viewAny', ChartAccount::class))->toBeFalse();
})->with([
    'purchasing manager' => [DashboardRole::PurchasingManager],
    'purchasing officer' => [DashboardRole::PurchasingOfficer],
]);

it('keeps the blanket bypass for an admin holding no fixed dashboard role', function (): void {
    (new AccountingPermissionSeeder)->run();

    $admin = User::factory()->admin()->create();
    $order = PurchaseOrder::factory()->sent()->create();

    expect($admin->can('viewAny', PurchaseOrder::class))->toBeTrue()
        ->and($admin->can('approve', $order))->toBeTrue()
        ->and($admin->can('viewAny', Ticket::class))->toBeTrue()
        ->and($admin->can('viewAny', CustomerProfile::class))->toBeTrue()
        ->and($admin->can('viewAny', EmployeeProfile::class))->toBeTrue()
        ->and($admin->can('viewAny', PricingTier::class))->toBeTrue()
        ->and($admin->can('viewAny', ChartAccount::class))->toBeTrue();
});

it('narrows an admin who is also given a purchasing role, in every other module too', function (): void {
    (new AccountingPermissionSeeder)->run();

    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::PurchasingOfficer->value);

    // Still reaches purchasing, because the officer role grants it explicitly.
    expect($admin->can('viewAny', PurchaseOrder::class))->toBeTrue()
        // But the bypass is gone everywhere, including for the purchasing
        // abilities the officer role does not carry.
        ->and($admin->can('approve', PurchaseOrder::factory()->sent()->create()))->toBeFalse()
        ->and($admin->can('viewAny', Ticket::class))->toBeFalse()
        ->and($admin->can('viewAny', CustomerProfile::class))->toBeFalse()
        ->and($admin->can('viewAny', EmployeeProfile::class))->toBeFalse()
        ->and($admin->can('viewAny', PricingTier::class))->toBeFalse()
        ->and($admin->can('viewAny', ChartAccount::class))->toBeFalse();
});

it('leaves an inventory catalogue manager able to reach suppliers, as they could before', function (): void {
    // SupplierPolicy replaced CatalogPolicy for suppliers. Grant on either
    // catalogue, so this is an addition rather than a silent regression on
    // shipped behaviour.
    (new InventoryPermissionSeeder)->run();

    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::CatalogView->value);
    $user->givePermissionTo(InventoryPermission::CatalogManage->value);

    expect($user->can('viewAny', Supplier::class))->toBeTrue()
        ->and($user->can('create', Supplier::class))->toBeTrue()
        ->and($user->can('viewAny', SupplierProductReference::class))->toBeTrue();
});
