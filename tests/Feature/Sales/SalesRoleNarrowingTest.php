<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\JournalEntry;
use App\Models\PricingTier;
use App\Models\PurchaseOrder;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Adding Sales Manager, Sales Officer, and Billing Officer to `DashboardRole`
 * narrows the `isAdmin()` bypass in every other module whose policies consult
 * `fixedRoleNames()` — CRM, pricing, Employees, Support, Accounting, and
 * Purchasing — not just Sales itself (FR-073).
 *
 * This mirrors `AccountingRoleNarrowingTest` and `PurchasingRoleNarrowingTest`
 * exactly; it is proven per module rather than assumed, because the enum's
 * narrowing effect is real behavioural change to already-shipped policies.
 */

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

it('keeps the blanket bypass for an admin holding no fixed dashboard role', function (): void {
    $admin = User::factory()->admin()->create();

    expect($admin->can('viewAny', Ticket::class))->toBeTrue()
        ->and($admin->can('viewAny', CustomerProfile::class))->toBeTrue()
        ->and($admin->can('viewAny', EmployeeProfile::class))->toBeTrue()
        ->and($admin->can('viewAny', PricingTier::class))->toBeTrue()
        ->and($admin->can('viewAny', ChartAccount::class))->toBeTrue();
});

it('narrows an admin to explicit permissions once a sales role is assigned', function (DashboardRole $role): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole($role->value);

    // None of the three sales roles grant a support, CRM, employee, pricing, or
    // full accounting permission, so a user who previously bypassed those
    // modules is now refused by them.
    expect($admin->can('viewAny', Ticket::class))->toBeFalse()
        ->and($admin->can('viewAny', CustomerProfile::class))->toBeFalse()
        ->and($admin->can('viewAny', EmployeeProfile::class))->toBeFalse()
        ->and($admin->can('viewAny', PricingTier::class))->toBeFalse()
        ->and($admin->can('viewAny', ChartAccount::class))->toBeFalse();
})->with([
    'sales manager' => [DashboardRole::SalesManager],
    'sales officer' => [DashboardRole::SalesOfficer],
    'billing officer' => [DashboardRole::BillingOfficer],
]);

it('narrows a sales role out of the Purchasing module', function (DashboardRole $role): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole($role->value);

    $order = PurchaseOrder::factory()->create();

    expect($admin->can('view', $order))->toBeFalse();
})->with([
    'sales manager' => [DashboardRole::SalesManager],
    'sales officer' => [DashboardRole::SalesOfficer],
    'billing officer' => [DashboardRole::BillingOfficer],
]);

it('registers all three sales roles in the central fixed-role catalogue', function (): void {
    expect(DashboardRole::fixedRoleNames())
        ->toContain('Sales Manager')
        ->toContain('Sales Officer')
        ->toContain('Billing Officer');
});

it('lets Sales Manager and Billing Officer post a sourced journal entry but not create a manual one', function (): void {
    // JournalEntryPolicy::post() picks the ability name from the entry's own
    // source_type — 'postFromSource' when set, plain 'post' otherwise — so a
    // sourced draft and an unsourced one are provably different acts to the
    // Gate (spec 019, ADR 0008).
    $sourced = JournalEntry::factory()->make(['source_type' => Ticket::class, 'source_id' => 1]);
    $unsourced = JournalEntry::factory()->make(['source_type' => null, 'source_id' => null]);

    foreach ([DashboardRole::SalesManager, DashboardRole::BillingOfficer] as $role) {
        $admin = User::factory()->admin()->create();
        $admin->assignRole($role->value);

        expect($admin->can('createFromSource', JournalEntry::class))->toBeTrue()
            ->and($admin->can('post', $sourced))->toBeTrue()
            ->and($admin->can('create', JournalEntry::class))->toBeFalse()
            ->and($admin->can('post', $unsourced))->toBeFalse();
    }
});
