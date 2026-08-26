<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Billing Officer and Sales Manager are granted exactly one accounting
 * permission — AccountingPermission::JournalEntryPostFromSource — so their
 * own sales documents can post through JournalPostingService. This file
 * proves that grant leaks nothing else: no accounting page, no manual entry
 * creation, no chart or fiscal-period access, and no reversal ability
 * (contracts/permissions.md §4).
 *
 * This is the corrected design: an earlier draft of this contract would have
 * granted the ordinary JournalEntryManage + JournalEntryPost pair, which maps
 * to the exact ability the Journal Entries dashboard page uses to decide
 * whether to show its own free-form "New Journal Entry" action — that would
 * have handed a Billing Officer the ability to post *any* manual entry, not
 * only ones their own document constructs. JournalEntryPostFromSource exists
 * so the two acts are provably different abilities to the Gate.
 */

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

it('grants Billing Officer exactly one accounting permission', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::BillingOfficer->value);

    $accountingPermissions = $admin->getAllPermissions()
        ->pluck('name')
        ->filter(fn (mixed $name): bool => is_string($name) && str_starts_with($name, 'accounting.'))
        ->values();

    expect($accountingPermissions->all())->toBe([AccountingPermission::JournalEntryPostFromSource->value]);
});

it('grants Sales Manager exactly one accounting permission', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::SalesManager->value);

    $accountingPermissions = $admin->getAllPermissions()
        ->pluck('name')
        ->filter(fn (mixed $name): bool => is_string($name) && str_starts_with($name, 'accounting.'))
        ->values();

    expect($accountingPermissions->all())->toBe([AccountingPermission::JournalEntryPostFromSource->value]);
});

it('keeps Billing Officer out of every accounting page', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::BillingOfficer->value);

    expect($admin->can('viewAny', ChartAccount::class))->toBeFalse()
        ->and($admin->can('viewAny', FiscalPeriod::class))->toBeFalse()
        ->and($admin->can('viewAny', JournalEntry::class))->toBeFalse();
});

it('refuses Billing Officer the ability to create a manual, unsourced journal entry', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::BillingOfficer->value);

    expect($admin->can('create', JournalEntry::class))->toBeFalse();
});

it('refuses Billing Officer the ability to reverse a posted entry, even one their own document produced', function (): void {
    (new AccountingPermissionSeeder)->run();

    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::BillingOfficer->value);

    $entry = JournalEntry::factory()->posted()->create(['source_type' => Ticket::class, 'source_id' => 1]);

    expect($admin->can('reverse', $entry))->toBeFalse();
});

it('refuses Billing Officer the ability to close or manage a fiscal period', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::BillingOfficer->value);

    $period = FiscalPeriod::factory()->create();

    expect($admin->can('close', $period))->toBeFalse()
        ->and($admin->can('update', $period))->toBeFalse();
});
