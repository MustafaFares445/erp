<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\AccountingDashboard;
use App\Filament\Resources\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\Taxes\TaxResource;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const ACCOUNTING_IMPLEMENTED_ITEMS = [
    'admin.resources.accounting_dashboard' => AccountingDashboard::class,
    'admin.resources.chart_of_accounts' => ChartOfAccountResource::class,
    'admin.resources.journal_entries' => JournalEntryResource::class,
    'admin.resources.fiscal_periods' => FiscalPeriodResource::class,
    'admin.resources.accounts_receivable' => AccountsReceivableResource::class,
    'admin.resources.accounts_payable' => AccountsPayableResource::class,
    'admin.resources.bills' => BillResource::class,
    'admin.resources.expenses' => ExpenseResource::class,
    'admin.resources.refunds' => RefundResource::class,
    'admin.resources.taxes' => TaxResource::class,
];

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->chief = User::factory()->admin()->create();
    $this->chief->assignRole(DashboardRole::ChiefAccountant->value);

    /** @var array{key: string, items: list<array{label: string, link: string}>} $group */
    $group = collect(AdminModuleRegistry::groups())->firstWhere('key', 'accounting');

    $this->accountingItems = collect($group['items']);
});

it('lists every accounting item as a real resource in sidebar order', function (): void {
    expect($this->accountingItems->pluck('label')->all())->toBe(array_keys(ACCOUNTING_IMPLEMENTED_ITEMS));
});

it('resolves a real URL for every accounting item', function (): void {
    $this->actingAs($this->chief);

    foreach (ACCOUNTING_IMPLEMENTED_ITEMS as $label => $resource) {
        $item = $this->accountingItems->firstWhere('label', $label);

        expect($item['link'])->toBe($resource)
            ->and(class_exists($item['link']))->toBeTrue()
            ->and(AdminModuleRegistry::resolveLink($item['link']))->toBe($resource::getUrl())
            ->and(AdminModuleRegistry::isAccessDenied($item['link']))->toBeFalse();
    }
});

it('reaches every accounting resource over HTTP as a chief accountant', function (): void {
    foreach (ACCOUNTING_IMPLEMENTED_ITEMS as $resource) {
        $this->actingAs($this->chief)
            ->get($resource::getUrl())
            ->assertOk();
    }
});

it('places accounting resources in the intended navigation slots', function (): void {
    expect(AccountingDashboard::getNavigationGroup())->toBeNull()
        ->and(AccountingDashboard::getNavigationSort())->toBeNull();

    foreach (array_slice(array_values(ACCOUNTING_IMPLEMENTED_ITEMS), 1) as $index => $resource) {
        expect($resource::getNavigationGroup())->toBe('admin.groups.accounting')
            ->and($resource::getNavigationSort())->toBe(201 + $index);
    }
});
