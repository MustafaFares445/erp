<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\DashboardRole;
use App\Enums\JournalEntryStatus;
use App\Enums\NormalBalance;
use App\Filament\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->chief = User::factory()->admin()->create();
    $this->chief->assignRole(DashboardRole::ChiefAccountant->value);

    app()->setLocale('en');
});

it('translates every accounting key rather than echoing the key back (FR-043)', function (): void {
    /** @var array<string, mixed> $accounting */
    $accounting = trans('admin.accounting', [], 'en');

    expect($accounting)->toBeArray();

    foreach (Arr::dot($accounting) as $key => $value) {
        $fullKey = 'admin.accounting.'.$key;

        expect($value)->toBeString()
            ->and($value)->not->toBe('')
            ->and(__($fullKey, [], 'en'))->not->toBe($fullKey);
    }
});

it('names the three resources and the accounting group in English', function (): void {
    expect(__('admin.groups.accounting', [], 'en'))->toBe('Accounting')
        ->and(__('admin.resources.chart_of_accounts', [], 'en'))->toBe('Chart of Accounts')
        ->and(__('admin.resources.journal_entries', [], 'en'))->toBe('Journal Entries')
        ->and(__('admin.resources.fiscal_periods', [], 'en'))->toBe('Fiscal Periods')
        ->and(ChartOfAccountResource::getNavigationLabel())->toBe('Chart of Accounts')
        ->and(JournalEntryResource::getNavigationLabel())->toBe('Journal Entries')
        ->and(FiscalPeriodResource::getNavigationLabel())->toBe('Fiscal Periods');
});

it('labels every enum case in English', function (): void {
    foreach (AccountElement::cases() as $element) {
        expect($element->label())->not->toBe('admin.accounting.element.'.$element->value);
    }

    foreach (NormalBalance::cases() as $normalBalance) {
        expect($normalBalance->label())->not->toBe('admin.accounting.normal_balance.'.$normalBalance->value);
    }

    foreach (JournalEntryStatus::cases() as $status) {
        expect($status->label())->not->toBe('admin.accounting.entry_status.'.$status->value);
    }

    expect(AccountElement::Asset->label())->toBe('Asset')
        ->and(NormalBalance::Credit->label())->toBe('Credit')
        ->and(JournalEntryStatus::Posted->label())->toBe('Posted');
});

it('renders the English field labels on the chart of accounts list page', function (): void {
    ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1100', 'name' => 'Cash on Hand']);

    $this->actingAs($this->chief)
        ->get(ChartOfAccountResource::getUrl())
        ->assertOk()
        ->assertSee('Account name')
        ->assertSee('Account type')
        ->assertSee('Parent account')
        ->assertSee('Accepts postings')
        ->assertSee('Balance');
});

it('renders the English field labels on the journal entries and fiscal periods list pages', function (): void {
    FiscalPeriod::factory()->create();

    $this->actingAs($this->chief)
        ->get(JournalEntryResource::getUrl())
        ->assertOk()
        ->assertSee('Entry number')
        ->assertSee('Entry date')
        ->assertSee('Fiscal period');

    $this->actingAs($this->chief)
        ->get(FiscalPeriodResource::getUrl())
        ->assertOk()
        ->assertSee('Period name')
        ->assertSee('Starts')
        ->assertSee('Ends')
        ->assertSee('Closed');
});

it('falls back to English for the accounting keys under the Arabic locale', function (): void {
    app()->setLocale('ar');

    // lang/ar/admin.php deliberately carries no accounting block; the note at the
    // top of that file records the fallback convention (FR-043).
    expect(__('admin.accounting.fields.entry_number'))->toBe('Entry number')
        ->and(__('admin.resources.fiscal_periods'))->toBe('Fiscal Periods');
});
