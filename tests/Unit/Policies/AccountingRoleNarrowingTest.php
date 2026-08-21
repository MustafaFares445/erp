<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\JournalEntry;
use App\Models\PricingTier;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Adding `Chief Accountant` and `Accountant` to `DashboardRole` narrows the
 * `isAdmin()` bypass in every module whose policies consult `fixedRoleNames()` —
 * CRM, pricing, Employees, and Support — not just Accounting.
 *
 * Core Inventory is deliberately not covered here: `ChecksInventoryPermissions`
 * has no admin bypass at all and goes straight to the permission check, so
 * `DashboardRole` cannot affect it either way.
 *
 * The narrowing is the intended behaviour of the central enum (research.md
 * R-006), but it is a real cross-module effect, so it is proven here rather than
 * assumed. These tests failing after a future role is added is the signal, not a
 * nuisance.
 */

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();
});

it('keeps the blanket bypass for an admin holding no fixed dashboard role', function (): void {
    $admin = User::factory()->admin()->create();

    expect($admin->can('viewAny', Ticket::class))->toBeTrue()
        ->and($admin->can('viewAny', CustomerProfile::class))->toBeTrue()
        ->and($admin->can('viewAny', EmployeeProfile::class))->toBeTrue()
        ->and($admin->can('viewAny', PricingTier::class))->toBeTrue();
});

it('narrows an admin to explicit permissions once an accounting role is assigned', function (DashboardRole $role): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole($role->value);

    // The accounting roles grant no support, CRM, employee, or pricing permission,
    // so a user who previously bypassed those modules is now refused by them.
    expect($admin->can('viewAny', Ticket::class))->toBeFalse()
        ->and($admin->can('viewAny', CustomerProfile::class))->toBeFalse()
        ->and($admin->can('viewAny', EmployeeProfile::class))->toBeFalse()
        ->and($admin->can('viewAny', PricingTier::class))->toBeFalse();
})->with([
    'chief accountant' => [DashboardRole::ChiefAccountant],
    'accountant' => [DashboardRole::Accountant],
]);

it('registers both accounting roles in the central fixed-role catalogue', function (): void {
    expect(DashboardRole::fixedRoleNames())
        ->toContain('Chief Accountant')
        ->toContain('Accountant');
});

it('still grants accounting abilities to a narrowed accounting role', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::Accountant->value);

    // Narrowing must not cost the role its own module's access, or the enum would
    // be trading one bug for another.
    expect($admin->can('viewAny', ChartAccount::class))->toBeTrue()
        ->and($admin->can('viewAny', JournalEntry::class))->toBeTrue();
});
