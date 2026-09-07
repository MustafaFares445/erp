<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\DashboardRole;
use App\Enums\JournalEntryStatus;
use App\Filament\Resources\ChartOfAccounts\Pages\CreateChartOfAccount;
use App\Filament\Resources\ChartOfAccounts\Pages\EditChartOfAccount;
use App\Filament\Resources\ChartOfAccounts\Pages\ListChartOfAccounts;
use App\Filament\Resources\ChartOfAccounts\Pages\ViewChartOfAccount;
use App\Filament\Resources\ChartOfAccounts\RelationManagers\LedgerRelationManager;
use App\Filament\Resources\FiscalPeriods\Pages\CreateFiscalPeriod;
use App\Filament\Resources\FiscalPeriods\Pages\EditFiscalPeriod;
use App\Filament\Resources\FiscalPeriods\Pages\ListFiscalPeriods;
use App\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Resources\JournalEntries\Pages\EditJournalEntry;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\AccountBalanceService;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->chief = User::factory()->admin()->create();
    $this->chief->assignRole(DashboardRole::ChiefAccountant->value);

    $this->accountant = User::factory()->admin()->create();
    $this->accountant->assignRole(DashboardRole::Accountant->value);

    $this->period = FiscalPeriod::factory()->create();
    $this->cash = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1100', 'name' => 'Cash on Hand']);
    $this->sales = ChartAccount::factory()->ofElement(AccountElement::Income)->create(['code' => '4100', 'name' => 'Product Sales']);
});

/**
 * A draft entry with two balanced lines, created the way the dashboard creates
 * one so the fixture cannot drift from the real write path.
 */
function draftEntryForResourceTest(string $amount = '250.00'): JournalEntry
{
    return app(JournalPostingService::class)->draft(
        test()->chief,
        CarbonImmutable::now(),
        [
            ['chart_account_id' => (int) test()->cash->getKey(), 'debit' => $amount, 'credit' => '0.00'],
            ['chart_account_id' => (int) test()->sales->getKey(), 'debit' => '0.00', 'credit' => $amount],
        ],
        'Cash sale',
    );
}

function postedEntryForResourceTest(string $amount = '250.00'): JournalEntry
{
    return app(JournalPostingService::class)->post(test()->chief, draftEntryForResourceTest($amount));
}

describe('chart of accounts', function (): void {
    it('renders the list, create, edit, and view pages', function (): void {
        Livewire::actingAs($this->chief)
            ->test(ListChartOfAccounts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->cash, $this->sales]);

        Livewire::actingAs($this->chief)
            ->test(CreateChartOfAccount::class)
            ->assertSuccessful();

        Livewire::actingAs($this->chief)
            ->test(EditChartOfAccount::class, ['record' => $this->cash->getRouteKey()])
            ->assertSuccessful();

        Livewire::actingAs($this->chief)
            ->test(ViewChartOfAccount::class, ['record' => $this->cash->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Cash on Hand');
    });

    it('creates an account through the service and demotes the parent it lands under', function (): void {
        $header = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1000', 'name' => 'Assets']);

        Livewire::actingAs($this->chief)
            ->test(CreateChartOfAccount::class)
            ->fillForm([
                'code' => '1120',
                'name' => 'Petty Cash',
                'account_type_id' => $header->account_type_id,
                'parent_id' => $header->getKey(),
                'is_postable' => true,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = ChartAccount::query()->where('code', '1120')->sole();

        expect($created->parent_id)->toBe($header->getKey())
            ->and($created->is_postable)->toBeTrue()
            // FR-008: the parent stops being a posting target the moment it gains
            // a child, and the service does that rather than refusing the save.
            ->and($header->refresh()->is_postable)->toBeFalse();
    });

    it('refuses a duplicate code, counting soft-deleted accounts (data-model.md C-1)', function (): void {
        $retired = ChartAccount::factory()->create(['code' => '1900']);
        $retired->delete();

        foreach (['1100', '1900'] as $takenCode) {
            Livewire::actingAs($this->chief)
                ->test(CreateChartOfAccount::class)
                ->fillForm([
                    'code' => $takenCode,
                    'name' => 'Duplicate attempt',
                    'account_type_id' => $this->cash->account_type_id,
                    'is_postable' => true,
                    'is_active' => true,
                ])
                ->call('create')
                ->assertHasFormErrors(['code']);
        }

        // A trashed account's code stays reserved: restoring it later would make
        // every historical line that points at it ambiguous.
        expect(ChartAccount::withTrashed()->where('code', '1900')->count())->toBe(1)
            ->and(ChartAccount::query()->where('code', '1100')->count())->toBe(1);
    });

    it('updates an account through the service', function (): void {
        Livewire::actingAs($this->chief)
            ->test(EditChartOfAccount::class, ['record' => $this->cash->getRouteKey()])
            ->fillForm(['name' => 'Cash and Equivalents', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->cash->refresh()->name)->toBe('Cash and Equivalents')
            ->and($this->cash->is_active)->toBeFalse();
    });

    it('refuses to delete an account that posted lines still reference', function (): void {
        postedEntryForResourceTest();

        Livewire::actingAs($this->chief)
            ->test(ListChartOfAccounts::class)
            ->callAction(TestAction::make('delete')->table($this->cash));

        expect($this->cash->refresh()->trashed())->toBeFalse();
    });

    it('deletes an unused account through the service', function (): void {
        $spare = ChartAccount::factory()->create(['code' => '1900']);

        Livewire::actingAs($this->chief)
            ->test(ListChartOfAccounts::class)
            ->callAction(TestAction::make('delete')->table($spare));

        expect($spare->refresh()->trashed())->toBeTrue();
    });

    it('shows a balance column that counts posted lines only and rolls up to the parent', function (): void {
        $header = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000']);
        $this->cash->update(['parent_id' => $header->getKey()]);

        Livewire::actingAs($this->chief)
            ->test(ListChartOfAccounts::class)
            ->assertSuccessful()
            ->assertDontSee('400.00');

        postedEntryForResourceTest('400.00');
        draftEntryForResourceTest('7777.00');

        Livewire::actingAs($this->chief)
            ->test(ListChartOfAccounts::class)
            ->assertSuccessful()
            // The debit-normal leaf and its header both read +400.00 — the header
            // by roll-up (FR-037) — while the draft's 7777.00 never appears.
            ->assertSee('400.00')
            ->assertDontSee('7777.00');

        $balances = app(AccountBalanceService::class);

        expect($balances->balanceFor($this->cash))->toBe('400.00')
            ->and($balances->balanceFor($header))->toBe('400.00')
            ->and($balances->balanceFor($header, includeDescendants: false))->toBe('0.00')
            // Credit-normal, so a credit balance reads positive too (FR-036).
            ->and($balances->balanceFor($this->sales))->toBe('400.00');
    });

    it('lists only posted lines in the ledger, with a running balance', function (): void {
        $posted = postedEntryForResourceTest('300.00');
        $draft = draftEntryForResourceTest('999.00');

        $postedLine = $posted->lines()->where('chart_account_id', $this->cash->getKey())->sole();
        $draftLine = $draft->lines()->where('chart_account_id', $this->cash->getKey())->sole();

        Livewire::actingAs($this->chief)
            ->test(LedgerRelationManager::class, [
                'ownerRecord' => $this->cash,
                'pageClass' => ViewChartOfAccount::class,
            ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$postedLine])
            ->assertCanNotSeeTableRecords([$draftLine])
            ->assertSee('300.00');
    });

    it('throws from the ledger relation manager when its owner record is somehow the wrong type', function (): void {
        $ledger = new LedgerRelationManager;
        $ledger->ownerRecord = User::factory()->create();

        $accountMethod = new ReflectionMethod($ledger, 'account');

        expect(fn (): mixed => $accountMethod->invoke($ledger))
            ->toThrow(LogicException::class, 'Expected the owner record of LedgerRelationManager to be a ChartAccount.');
    });
});

describe('journal entries', function (): void {
    it('renders the list, create, edit, and view pages', function (): void {
        $entry = draftEntryForResourceTest();

        Livewire::actingAs($this->chief)
            ->test(ListJournalEntries::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$entry]);

        Livewire::actingAs($this->chief)
            ->test(CreateJournalEntry::class)
            ->assertSuccessful();

        Livewire::actingAs($this->chief)
            ->test(EditJournalEntry::class, ['record' => $entry->getRouteKey()])
            ->assertSuccessful();

        Livewire::actingAs($this->chief)
            ->test(ViewJournalEntry::class, ['record' => $entry->getRouteKey()])
            ->assertSuccessful()
            ->assertSee($entry->entry_number);
    });

    it('creates a draft with its lines from the form', function (): void {
        Livewire::actingAs($this->chief)
            ->test(CreateJournalEntry::class)
            ->fillForm([
                'entry_date' => CarbonImmutable::now()->toDateString(),
                'description' => 'Opening cash',
                'lines' => [
                    ['chart_account_id' => $this->cash->getKey(), 'debit' => '75.50', 'credit' => '0', 'description' => 'In'],
                    ['chart_account_id' => $this->sales->getKey(), 'debit' => '0', 'credit' => '75.50', 'description' => 'Out'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = JournalEntry::query()->sole();

        expect($entry->status)->toBe(JournalEntryStatus::Draft)
            ->and($entry->entry_number)->toBe('JE-000001')
            ->and($entry->fiscal_period_id)->toBeNull()
            ->and($entry->lines)->toHaveCount(2)
            ->and($entry->lines->pluck('sort_order')->all())->toBe([1, 2]);
    });

    it('edits a draft freely — date, description, and the lines themselves', function (): void {
        $entry = draftEntryForResourceTest('250.00');
        $spare = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1110']);

        Livewire::actingAs($this->chief)
            ->test(EditJournalEntry::class, ['record' => $entry->getRouteKey()])
            ->fillForm([
                'description' => 'Corrected before posting',
                'lines' => [
                    ['chart_account_id' => $spare->getKey(), 'debit' => '90.00', 'credit' => '0', 'description' => 'Moved to the bank'],
                    ['chart_account_id' => $this->sales->getKey(), 'debit' => '0', 'credit' => '90.00', 'description' => null],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $entry->refresh()->load('lines');

        expect($entry->description)->toBe('Corrected before posting')
            ->and($entry->status)->toBe(JournalEntryStatus::Draft)
            ->and($entry->lines)->toHaveCount(2)
            ->and($entry->lines->pluck('chart_account_id')->all())
            ->toBe([$spare->getKey(), $this->sales->getKey()])
            ->and($entry->lines->pluck('debit')->all())->toBe(['90.00', '0.00']);
    });

    it('posts a draft from the view page and resolves its period', function (): void {
        $entry = draftEntryForResourceTest();

        Livewire::actingAs($this->chief)
            ->test(ViewJournalEntry::class, ['record' => $entry->getRouteKey()])
            ->callAction('post');

        expect($entry->refresh()->status)->toBe(JournalEntryStatus::Posted)
            ->and($entry->fiscal_period_id)->toBe($this->period->getKey());
    });

    it('refuses to post an unbalanced draft and says why', function (): void {
        $entry = app(JournalPostingService::class)->draft(
            $this->chief,
            CarbonImmutable::now(),
            [
                ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '10.00', 'credit' => '0.00'],
                ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '9.00'],
            ],
        );

        Livewire::actingAs($this->chief)
            ->test(ViewJournalEntry::class, ['record' => $entry->getRouteKey()])
            ->callAction('post')
            ->assertNotified();

        expect($entry->refresh()->status)->toBe(JournalEntryStatus::Draft);
    });

    it('reverses a posted entry from the table with a mirrored, net-zero pair', function (): void {
        $entry = postedEntryForResourceTest('120.00');

        Livewire::actingAs($this->chief)
            ->test(ListJournalEntries::class)
            ->callAction(TestAction::make('reverse')->table($entry), [
                'reversal_date' => CarbonImmutable::now()->toDateString(),
                'description' => 'Booked to the wrong account',
            ]);

        $reversal = $entry->refresh()->reversal;

        expect($reversal)->not->toBeNull()
            ->and($reversal->status)->toBe(JournalEntryStatus::Posted)
            ->and($reversal->description)->toBe('Booked to the wrong account')
            ->and($reversal->lines->firstWhere('chart_account_id', $this->cash->getKey())->credit)->toBe('120.00');
    });

    it('hides edit, delete, and post on a posted entry and offers reverse instead', function (): void {
        $entry = postedEntryForResourceTest();

        Livewire::actingAs($this->chief)
            ->test(ListJournalEntries::class)
            ->assertActionHidden(TestAction::make('edit')->table($entry))
            ->assertActionHidden(TestAction::make('delete')->table($entry))
            ->assertActionHidden(TestAction::make('post')->table($entry))
            ->assertActionVisible(TestAction::make('reverse')->table($entry));
    });

    it('offers post but not reverse on a draft', function (): void {
        $entry = draftEntryForResourceTest();

        Livewire::actingAs($this->chief)
            ->test(ListJournalEntries::class)
            ->assertActionVisible(TestAction::make('post')->table($entry))
            ->assertActionHidden(TestAction::make('reverse')->table($entry));
    });

    it('renders a posted entry read-only on its view page', function (): void {
        $entry = postedEntryForResourceTest();

        Livewire::actingAs($this->chief)
            ->test(ViewJournalEntry::class, ['record' => $entry->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('This entry is posted and can no longer be edited. Post a reversing entry to correct it.');
    });
});

describe('fiscal periods', function (): void {
    it('renders the list, create, and edit pages', function (): void {
        Livewire::actingAs($this->chief)
            ->test(ListFiscalPeriods::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->period]);

        Livewire::actingAs($this->chief)
            ->test(CreateFiscalPeriod::class)
            ->assertSuccessful();

        Livewire::actingAs($this->chief)
            ->test(EditFiscalPeriod::class, ['record' => $this->period->getRouteKey()])
            ->assertSuccessful();
    });

    it('creates and updates a period through the service', function (): void {
        $nextMonth = CarbonImmutable::now()->addMonthNoOverflow()->startOfMonth();

        Livewire::actingAs($this->chief)
            ->test(CreateFiscalPeriod::class)
            ->fillForm([
                'name' => $nextMonth->format('F Y'),
                'starts_at' => $nextMonth->toDateString(),
                'ends_at' => $nextMonth->endOfMonth()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = FiscalPeriod::query()->where('name', $nextMonth->format('F Y'))->sole();

        Livewire::actingAs($this->chief)
            ->test(EditFiscalPeriod::class, ['record' => $created->getRouteKey()])
            ->fillForm(['name' => 'Renamed period'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($created->refresh()->name)->toBe('Renamed period');
    });

    it('refuses a period overlapping an existing one and says which', function (): void {
        Livewire::actingAs($this->chief)
            ->test(CreateFiscalPeriod::class)
            ->fillForm([
                'name' => 'Overlapping',
                'starts_at' => $this->period->starts_at->toDateString(),
                'ends_at' => $this->period->ends_at->toDateString(),
            ])
            ->call('create')
            ->assertNotified();

        expect(FiscalPeriod::query()->count())->toBe(1);
    });

    it('closes and reopens a period through the service', function (): void {
        // WP-2.5's close gate delegates to services that resolve real chart-of-accounts
        // configuration (deferred tax, tax payable, receivable) — this file's own fixture
        // only creates two ad-hoc accounts, so a clean chart and `SalesSetting` are seeded
        // here to let a period with no transactions close on an otherwise-empty ledger.
        (new ChartOfAccountsSeeder)->run();
        SalesSetting::current()->forceFill([
            'receivable_account_id' => ChartAccount::query()->where('code', '1200')->value('id'),
            'revenue_account_id' => ChartAccount::query()->where('code', '4100')->value('id'),
            'deferred_tax_account_id' => ChartAccount::query()->where('code', '2350')->value('id'),
            'tax_payable_account_id' => ChartAccount::query()->where('code', '2300')->value('id'),
        ])->save();

        Livewire::actingAs($this->chief)
            ->test(ListFiscalPeriods::class)
            ->callAction(TestAction::make('close')->table($this->period));

        expect($this->period->refresh()->is_closed)->toBeTrue();

        Livewire::actingAs($this->chief)
            ->test(ListFiscalPeriods::class)
            ->callAction(TestAction::make('reopen')->table($this->period));

        expect($this->period->refresh()->is_closed)->toBeFalse();
    });

    it('refuses to delete a period holding journal entries', function (): void {
        postedEntryForResourceTest();

        Livewire::actingAs($this->chief)
            ->test(ListFiscalPeriods::class)
            ->callAction(TestAction::make('delete')->table($this->period));

        expect(FiscalPeriod::query()->whereKey($this->period->getKey())->exists())->toBeTrue();
    });
});

describe('the accountant / chief accountant separation', function (): void {
    it('shows an accountant post but not reverse, and not close (SC-005)', function (): void {
        $draft = draftEntryForResourceTest();
        $posted = postedEntryForResourceTest();

        Livewire::actingAs($this->accountant)
            ->test(ListJournalEntries::class)
            ->assertActionVisible(TestAction::make('post')->table($draft))
            ->assertActionHidden(TestAction::make('reverse')->table($posted));

        Livewire::actingAs($this->accountant)
            ->test(ListFiscalPeriods::class)
            ->assertActionHidden(TestAction::make('close')->table($this->period));
    });

    it('shows a chief accountant reverse and close', function (): void {
        $posted = postedEntryForResourceTest();

        Livewire::actingAs($this->chief)
            ->test(ListJournalEntries::class)
            ->assertActionVisible(TestAction::make('reverse')->table($posted));

        Livewire::actingAs($this->chief)
            ->test(ListFiscalPeriods::class)
            ->assertActionVisible(TestAction::make('close')->table($this->period));
    });
});
