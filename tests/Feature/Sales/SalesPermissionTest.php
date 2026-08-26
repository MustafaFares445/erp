<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Enums\SalesPermission;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

it('seeds exactly the twenty-six sales permissions, each namespaced under sales', function (): void {
    $seeded = Permission::query()->where('name', 'like', 'sales.%')->pluck('name');

    expect($seeded)->toHaveCount(26)
        ->and($seeded->all())->toEqualCanonicalizing(SalesPermission::values());
});

it('additionally seeds exactly one accounting permission for the sales module to use', function (): void {
    expect(Permission::query()->where('name', AccountingPermission::JournalEntryPostFromSource->value)->exists())->toBeTrue();
});

it('grants System Admin every sales permission', function (): void {
    $role = Role::findByName(DashboardRole::SystemAdmin->value, 'web');

    expect($role->permissions->pluck('name')->all())->toEqualCanonicalizing(SalesPermission::values());
});

it('gives Sales Manager quotation and order authority but no payment ability', function (): void {
    $role = Role::findByName(DashboardRole::SalesManager->value, 'web');
    $names = $role->permissions->pluck('name')->all();

    expect($names)->toContain(SalesPermission::QuotationConvert->value)
        ->toContain(SalesPermission::CreditNoteConfirm->value)
        ->toContain(AccountingPermission::JournalEntryPostFromSource->value)
        ->and($names)->not->toContain(SalesPermission::PaymentRecord->value)
        ->and($names)->not->toContain(SalesPermission::PaymentReverse->value);
});

it('gives Sales Officer quotation authority but no invoice, payment, or credit-note ability', function (): void {
    $role = Role::findByName(DashboardRole::SalesOfficer->value, 'web');
    $names = $role->permissions->pluck('name')->all();

    expect($names)->toContain(SalesPermission::QuotationManage->value)
        ->toContain(SalesPermission::QuotationDecide->value)
        ->toContain(SalesPermission::InvoiceConfirmReceipt->value)
        ->and($names)->not->toContain(SalesPermission::InvoiceIssue->value)
        ->and($names)->not->toContain(SalesPermission::PaymentRecord->value)
        ->and($names)->not->toContain(SalesPermission::CreditNoteManage->value)
        ->and($names)->not->toContain(SalesPermission::QuotationConvert->value);
});

it('gives Billing Officer invoice and payment authority but no conversion or credit-note confirmation', function (): void {
    $role = Role::findByName(DashboardRole::BillingOfficer->value, 'web');
    $names = $role->permissions->pluck('name')->all();

    expect($names)->toContain(SalesPermission::InvoiceIssue->value)
        ->toContain(SalesPermission::PaymentRecord->value)
        ->toContain(SalesPermission::CreditNoteManage->value)
        ->toContain(AccountingPermission::JournalEntryPostFromSource->value)
        ->and($names)->not->toContain(SalesPermission::QuotationConvert->value)
        ->and($names)->not->toContain(SalesPermission::CreditNoteConfirm->value)
        ->and($names)->not->toContain(SalesPermission::PaymentReverse->value)
        ->and($names)->not->toContain(SalesPermission::CreditNoteReverse->value);
});

it('gives Reviewer read-only access across every sales surface (FR-074)', function (): void {
    $role = Role::findByName(DashboardRole::Reviewer->value, 'web');
    $names = $role->permissions->pluck('name')->all();

    foreach ($names as $name) {
        expect($name)->toEndWith('.view');
    }

    expect($names)->toContain(SalesPermission::QuotationView->value)
        ->toContain(SalesPermission::InvoiceView->value)
        ->toContain(SalesPermission::PaymentView->value)
        ->toContain(SalesPermission::CreditNoteView->value);
});

it('reserves payment and credit-note reversal for System Admin only', function (): void {
    foreach ([DashboardRole::SalesManager, DashboardRole::SalesOfficer, DashboardRole::BillingOfficer] as $role) {
        $names = Role::findByName($role->value, 'web')->permissions->pluck('name')->all();

        expect($names)->not->toContain(SalesPermission::PaymentReverse->value)
            ->and($names)->not->toContain(SalesPermission::CreditNoteReverse->value);
    }
});

it('is idempotent', function (): void {
    (new SalesPermissionSeeder)->run();

    expect(Permission::query()->where('name', 'like', 'sales.%')->count())->toBe(26);
});
