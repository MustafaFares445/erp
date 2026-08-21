<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The three items backed by a real resource. Everything else in the `accounting`
 * group is still unbuilt and must stay a placeholder (SC-004, FR-042).
 */
const ACCOUNTING_BUILT_ITEMS = [
    'admin.resources.chart_of_accounts' => ChartOfAccountResource::class,
    'admin.resources.journal_entries' => JournalEntryResource::class,
    'admin.resources.fiscal_periods' => FiscalPeriodResource::class,
];

const ACCOUNTING_PLACEHOLDER_ITEMS = [
    'admin.resources.accounts_receivable',
    'admin.resources.accounts_payable',
    'admin.resources.bills',
    'admin.resources.expenses',
    'admin.resources.refunds',
    'admin.resources.taxes',
    'admin.resources.financial_reports',
];

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->chief = User::factory()->admin()->create();
    $this->chief->assignRole(DashboardRole::ChiefAccountant->value);

    /** @var array{key: string, items: list<array{label: string, link: string}>} $group */
    $group = collect(AdminModuleRegistry::groups())->firstWhere('key', 'accounting');

    $this->accountingItems = collect($group['items']);
});

it('lists the three built resources in the accounting group, in order, before the placeholders', function (): void {
    $labels = $this->accountingItems->pluck('label')->all();

    expect(array_slice($labels, 0, 3))->toBe(array_keys(ACCOUNTING_BUILT_ITEMS))
        ->and(array_slice($labels, 3))->toBe(ACCOUNTING_PLACEHOLDER_ITEMS);
});

it('resolves a real url for each of the three built items', function (): void {
    $this->actingAs($this->chief);

    foreach (ACCOUNTING_BUILT_ITEMS as $label => $resource) {
        $item = $this->accountingItems->firstWhere('label', $label);

        expect($item['link'])->toBe($resource)
            ->and(AdminModuleRegistry::resolveLink($item['link']))->toBe($resource::getUrl())
            ->and(AdminModuleRegistry::isAccessDenied($item['link']))->toBeFalse();
    }
});

it('still resolves no link for the seven unbuilt accounting items, which fall back to the placeholder', function (): void {
    $this->actingAs($this->chief);

    foreach (ACCOUNTING_PLACEHOLDER_ITEMS as $label) {
        $item = $this->accountingItems->firstWhere('label', $label);

        // The class named by an unbuilt item does not exist, so resolveLink()
        // returns null and the sidebar renders ModulePlaceholder in its place.
        expect(class_exists($item['link']))->toBeFalse()
            ->and(AdminModuleRegistry::resolveLink($item['link']))->toBeNull();
    }
});

it('reaches each built resource over http as a chief accountant', function (): void {
    foreach (ACCOUNTING_BUILT_ITEMS as $resource) {
        $this->actingAs($this->chief)
            ->get($resource::getUrl())
            ->assertOk();
    }
});

it('places the three resources in the accounting navigation group with sequential sort values', function (): void {
    expect(ChartOfAccountResource::getNavigationGroup())->toBe('admin.groups.accounting')
        ->and(JournalEntryResource::getNavigationGroup())->toBe('admin.groups.accounting')
        ->and(FiscalPeriodResource::getNavigationGroup())->toBe('admin.groups.accounting')
        // The accounting group is second, so its reserved sort range is 200-299.
        ->and(ChartOfAccountResource::getNavigationSort())->toBe(201)
        ->and(JournalEntryResource::getNavigationSort())->toBe(202)
        ->and(FiscalPeriodResource::getNavigationSort())->toBe(203);
});
