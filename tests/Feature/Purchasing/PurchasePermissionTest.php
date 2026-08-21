<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Models\PurchaseOrder;
use App\Models\PurchaseSetting;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierProductReference;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
});

function purchasingUser(DashboardRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

it('seeds every catalogue permission and grants System Admin all of them', function (): void {
    // System Admin is seeded from `values()` rather than an enumerated list, so
    // a permission added later is never silently withheld. This asserts that
    // property, not just today's count.
    $admin = purchasingUser(DashboardRole::SystemAdmin);

    foreach (PurchasePermission::values() as $permission) {
        expect($admin->can($permission))->toBeTrue($permission);
    }
});

/*
 * The role matrix from contracts/permissions.md §2, ability by ability.
 * The order is receivable and has no completed receipt, so record-shaped rules
 * (R-C, R-D) do not mask a permission answer here — those get their own tests.
 */
dataset('purchaseOrderMatrix', [
    'system admin' => [DashboardRole::SystemAdmin, [
        'viewAny' => true, 'view' => true, 'create' => true,
        'approve' => true, 'send' => true, 'cancel' => true, 'close' => true, 'receive' => true, 'viewAudit' => true,
    ]],
    'purchasing manager' => [DashboardRole::PurchasingManager, [
        'viewAny' => true, 'view' => true, 'create' => true,
        'approve' => true, 'send' => true, 'cancel' => true, 'close' => true, 'receive' => true, 'viewAudit' => true,
    ]],
    'purchasing officer' => [DashboardRole::PurchasingOfficer, [
        'viewAny' => true, 'view' => true, 'create' => true,
        'approve' => false, 'send' => false, 'cancel' => false, 'close' => false, 'receive' => true, 'viewAudit' => false,
    ]],
    'reviewer' => [DashboardRole::Reviewer, [
        'viewAny' => true, 'view' => true, 'create' => false,
        'approve' => false, 'send' => false, 'cancel' => false, 'close' => false, 'receive' => false, 'viewAudit' => true,
    ]],
]);

it('applies the purchase order matrix', function (DashboardRole $role, array $expected): void {
    $user = purchasingUser($role);
    $order = PurchaseOrder::factory()->sent()->create();

    foreach ($expected as $ability => $allowed) {
        expect($user->can($ability, $order))->toBe($allowed, sprintf('%s / %s', $role->value, $ability));
    }
})->with('purchaseOrderMatrix');

it('lets an officer edit and submit a draft but not an order that has left draft', function (): void {
    $officer = purchasingUser(DashboardRole::PurchasingOfficer);

    $draft = PurchaseOrder::factory()->create();
    $sent = PurchaseOrder::factory()->sent()->create();

    expect($officer->can('update', $draft))->toBeTrue()
        ->and($officer->can('delete', $draft))->toBeTrue()
        ->and($officer->can('submit', $draft))->toBeTrue()
        // R-C: immutability outranks permission. The officer holds
        // `purchase.order.manage`, and it still refuses.
        ->and($officer->can('update', $sent))->toBeFalse()
        ->and($officer->can('delete', $sent))->toBeFalse()
        ->and($officer->can('submit', $sent))->toBeFalse();
});

it('refuses to restore a purchasing record for anyone but a System Admin', function (): void {
    $order = PurchaseOrder::factory()->create();

    expect(purchasingUser(DashboardRole::SystemAdmin)->can('restore', $order))->toBeTrue()
        ->and(purchasingUser(DashboardRole::PurchasingManager)->can('restore', $order))->toBeFalse()
        ->and(purchasingUser(DashboardRole::PurchasingOfficer)->can('restore', $order))->toBeFalse()
        ->and(purchasingUser(DashboardRole::Reviewer)->can('restore', $order))->toBeFalse();
});

it('never permits a force delete, for any dashboard role or a plain admin', function (): void {
    $order = PurchaseOrder::factory()->create();

    // Every role, not just the purchasing ones: `forceDelete()` returns false
    // unconditionally, so a role from another module must not find a way
    // through either. Roles outside this module's seeder are created here so
    // the loop covers the whole enum rather than the subset that happens to
    // exist.
    foreach (DashboardRole::cases() as $role) {
        Role::findOrCreate($role->value, 'web');

        expect(purchasingUser($role)->can('forceDelete', $order))->toBeFalse($role->value);
    }

    // And an admin holding no fixed role at all, who otherwise bypasses.
    expect(User::factory()->admin()->create()->can('forceDelete', $order))->toBeFalse();
});

it('refuses receiving against an order that is not receivable, even for a manager', function (): void {
    $manager = purchasingUser(DashboardRole::PurchasingManager);

    foreach (PurchaseOrderStatus::cases() as $status) {
        $order = PurchaseOrder::factory()->create(['status' => $status]);

        expect($manager->can('receive', $order))->toBe($status->isReceivable(), $status->value);
    }
});

it('refuses cancellation once a receipt has completed, whatever the role (R-D)', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $order->receipts()->create([
        'operation_type' => 'receipt',
        'destination_warehouse_id' => $order->destination_warehouse_id,
        'supplier_id' => $order->supplier_id,
    ])->forceFill(['completed_at' => now(), 'stage' => 'done'])->save();

    foreach ([DashboardRole::SystemAdmin, DashboardRole::PurchasingManager] as $role) {
        expect(purchasingUser($role)->can('cancel', $order->fresh()))->toBeFalse($role->value);
    }
});

dataset('confirmationMatrix', [
    'system admin' => [DashboardRole::SystemAdmin, ['viewAny' => true, 'create' => true]],
    'purchasing manager' => [DashboardRole::PurchasingManager, ['viewAny' => true, 'create' => true]],
    'purchasing officer' => [DashboardRole::PurchasingOfficer, ['viewAny' => true, 'create' => true]],
    'reviewer' => [DashboardRole::Reviewer, ['viewAny' => true, 'create' => false]],
]);

it('applies the supplier confirmation matrix', function (DashboardRole $role, array $expected): void {
    $user = purchasingUser($role);

    foreach ($expected as $ability => $allowed) {
        expect($user->can($ability, SupplierConfirmation::class))->toBe($allowed, sprintf('%s / %s', $role->value, $ability));
    }
})->with('confirmationMatrix');

it('never permits editing or deleting a confirmation, and permits answering only while pending (R-E)', function (): void {
    $pending = SupplierConfirmation::factory()->create();
    $answered = SupplierConfirmation::factory()->confirmed()->create();

    $manager = purchasingUser(DashboardRole::PurchasingManager);

    expect($manager->can('answer', $pending))->toBeTrue()
        ->and($manager->can('answer', $answered))->toBeFalse()
        ->and($manager->can('update', $pending))->toBeFalse()
        ->and($manager->can('update', $answered))->toBeFalse()
        ->and($manager->can('delete', $answered))->toBeFalse();
});

dataset('supplierMatrix', [
    'system admin' => [DashboardRole::SystemAdmin, ['viewAny' => true, 'create' => true, 'update' => true]],
    'purchasing manager' => [DashboardRole::PurchasingManager, ['viewAny' => true, 'create' => true, 'update' => true]],
    'purchasing officer' => [DashboardRole::PurchasingOfficer, ['viewAny' => true, 'create' => false, 'update' => false]],
    'reviewer' => [DashboardRole::Reviewer, ['viewAny' => true, 'create' => false, 'update' => false]],
]);

it('applies the supplier matrix', function (DashboardRole $role, array $expected): void {
    $user = purchasingUser($role);
    $supplier = Supplier::factory()->create();

    foreach ($expected as $ability => $allowed) {
        expect($user->can($ability, $supplier))->toBe($allowed, sprintf('%s / %s', $role->value, $ability));
    }
})->with('supplierMatrix');

it('refuses to delete a supplier that has a purchase order', function (): void {
    $admin = purchasingUser(DashboardRole::SystemAdmin);
    $free = Supplier::factory()->create();
    $committed = Supplier::factory()->create();
    PurchaseOrder::factory()->for($committed)->create();

    expect($admin->can('delete', $free))->toBeTrue()
        ->and($admin->can('delete', $committed))->toBeFalse();
});

dataset('productReferenceMatrix', [
    'system admin' => [DashboardRole::SystemAdmin, ['viewAny' => true, 'create' => true, 'update' => true]],
    'purchasing manager' => [DashboardRole::PurchasingManager, ['viewAny' => true, 'create' => true, 'update' => true]],
    'purchasing officer' => [DashboardRole::PurchasingOfficer, ['viewAny' => true, 'create' => false, 'update' => false]],
    'reviewer' => [DashboardRole::Reviewer, ['viewAny' => true, 'create' => false, 'update' => false]],
]);

it('applies the supplier product reference matrix', function (DashboardRole $role, array $expected): void {
    $user = purchasingUser($role);
    $reference = SupplierProductReference::factory()->create();

    foreach ($expected as $ability => $allowed) {
        expect($user->can($ability, $reference))->toBe($allowed, sprintf('%s / %s', $role->value, $ability));
    }
})->with('productReferenceMatrix');

it('grants the approval threshold to System Admin alone', function (): void {
    $setting = PurchaseSetting::factory()->create();

    expect(purchasingUser(DashboardRole::SystemAdmin)->can('update', $setting))->toBeTrue()
        // A manager who could raise the threshold could approve their own
        // spending by moving the line instead of by breaking a rule.
        ->and(purchasingUser(DashboardRole::PurchasingManager)->can('update', $setting))->toBeFalse()
        ->and(purchasingUser(DashboardRole::PurchasingOfficer)->can('update', $setting))->toBeFalse()
        ->and(purchasingUser(DashboardRole::Reviewer)->can('update', $setting))->toBeFalse();
});

it('never permits deleting the settings singleton, because that silently restores the zero default', function (): void {
    $setting = PurchaseSetting::factory()->create();

    expect(purchasingUser(DashboardRole::SystemAdmin)->can('delete', $setting))->toBeFalse();
});
