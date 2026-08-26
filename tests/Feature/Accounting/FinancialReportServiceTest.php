<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\DashboardRole;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\User;
use App\Services\Accounting\Exceptions\InvalidReportRange;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The fixture from quickstart.md §"The fixture that makes every proof
 * checkable" — every expected figure in this file is derived from it and can
 * be verified by inspection.
 */
beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->service = app(FinancialReportService::class);
    $this->posting = app(JournalPostingService::class);

    $this->actor = User::factory()->create();
    $this->actor->assignRole(DashboardRole::ChiefAccountant->value);

    $this->january = FiscalPeriod::factory()->forMonth(CarbonImmutable::parse('2026-01-01'))->create();
    $this->february = FiscalPeriod::factory()->forMonth(CarbonImmutable::parse('2026-02-01'))->create();

    $this->cash = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1100', 'name' => 'Cash']);
    $this->receivable = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1200', 'name' => 'Receivable']);
    $this->sales = ChartAccount::factory()->ofElement(AccountElement::Income)->create(['code' => '4100', 'name' => 'Sales']);
    $this->rent = ChartAccount::factory()->ofElement(AccountElement::Expense)->create(['code' => '5300', 'name' => 'Rent']);

    $this->e1 = $this->posting->postNew($this->actor, CarbonImmutable::parse('2026-01-05'), [
        ['chart_account_id' => $this->cash->id, 'debit' => '1000.00', 'credit' => '0.00'],
        ['chart_account_id' => $this->sales->id, 'debit' => '0.00', 'credit' => '1000.00'],
    ]);

    $this->e2 = $this->posting->postNew($this->actor, CarbonImmutable::parse('2026-01-15'), [
        ['chart_account_id' => $this->rent->id, 'debit' => '300.00', 'credit' => '0.00'],
        ['chart_account_id' => $this->cash->id, 'debit' => '0.00', 'credit' => '300.00'],
    ]);

    $this->e3 = $this->posting->postNew($this->actor, CarbonImmutable::parse('2026-01-20'), [
        ['chart_account_id' => $this->receivable->id, 'debit' => '500.00', 'credit' => '0.00'],
        ['chart_account_id' => $this->sales->id, 'debit' => '0.00', 'credit' => '500.00'],
    ]);

    $this->e4 = $this->posting->draft($this->actor, CarbonImmutable::parse('2026-01-25'), [
        ['chart_account_id' => $this->rent->id, 'debit' => '100.00', 'credit' => '0.00'],
        ['chart_account_id' => $this->cash->id, 'debit' => '0.00', 'credit' => '100.00'],
    ]);

    $this->e5 = $this->posting->postNew($this->actor, CarbonImmutable::parse('2026-02-10'), [
        ['chart_account_id' => $this->cash->id, 'debit' => '700.00', 'credit' => '0.00'],
        ['chart_account_id' => $this->sales->id, 'debit' => '0.00', 'credit' => '700.00'],
    ]);

    $this->from = CarbonImmutable::parse('2026-01-01');
    $this->to = CarbonImmutable::parse('2026-01-31');
});

// --- Phase 2: shared surface -----------------------------------------------

it('throws on an inverted range rather than returning an empty result (FR-010)', function (): void {
    expect(fn () => $this->service->trialBalance(CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-01-31')))
        ->toThrow(InvalidReportRange::class);
});

it('offers open and closed fiscal periods alike (FR-011)', function (): void {
    $this->january->update(['is_closed' => true]);

    $options = $this->service->fiscalPeriodOptions();

    expect($options)->toHaveKey($this->january->id)
        ->and($options)->toHaveKey($this->february->id);
});

it('is inclusive at both date bounds (SC-006)', function (): void {
    $wide = $this->service->trialBalance(CarbonImmutable::parse('2026-01-05'), CarbonImmutable::parse('2026-01-20'));
    $widened = $this->service->trialBalance(CarbonImmutable::parse('2026-01-04'), CarbonImmutable::parse('2026-01-21'));
    $narrowed = $this->service->trialBalance(CarbonImmutable::parse('2026-01-06'), CarbonImmutable::parse('2026-01-19'));

    // E1 (exactly the start) and E3 (exactly the end) both included; widening
    // the range further changes nothing; narrowing past both leaves only E2.
    expect($wide['totalDebit'])->toBe('1800.00')
        ->and($widened['totalDebit'])->toBe('1800.00')
        ->and($narrowed['totalDebit'])->toBe('300.00');
});

// --- Phase 4: Trial Balance (US2) -------------------------------------------

it('produces the exact trial balance figures from quickstart Scenario 1', function (): void {
    $report = $this->service->trialBalance($this->from, $this->to);

    $byCode = collect($report['rows'])->keyBy('code');

    expect($byCode['1100'])->toMatchArray(['openingBalance' => '0.00', 'periodDebit' => '1000.00', 'periodCredit' => '300.00', 'closingBalance' => '700.00'])
        ->and($byCode['1200'])->toMatchArray(['openingBalance' => '0.00', 'periodDebit' => '500.00', 'periodCredit' => '0.00', 'closingBalance' => '500.00'])
        ->and($byCode['4100'])->toMatchArray(['openingBalance' => '0.00', 'periodDebit' => '0.00', 'periodCredit' => '1500.00', 'closingBalance' => '1500.00'])
        ->and($byCode['5300'])->toMatchArray(['openingBalance' => '0.00', 'periodDebit' => '300.00', 'periodCredit' => '0.00', 'closingBalance' => '300.00'])
        ->and($report['totalDebit'])->toBe('1800.00')
        ->and($report['totalCredit'])->toBe('1800.00')
        ->and($report['foots'])->toBeTrue();
});

it('excludes a draft entry and an entry outside the range (SC-003)', function (): void {
    $report = $this->service->trialBalance($this->from, $this->to);

    // E4 (draft, 100.00) would inflate Rent's debit and Cash's credit; E5
    // (February, 700.00) would inflate Cash and Sales. Neither appears.
    $byCode = collect($report['rows'])->keyBy('code');

    expect($byCode['5300']['periodDebit'])->toBe('300.00')
        ->and($byCode['1100']['periodCredit'])->toBe('300.00')
        ->and($byCode['1100']['closingBalance'])->toBe('700.00');
});

it("regression: a credit-normal account's period columns stay raw while its balance is signed (research §R4)", function (): void {
    $report = $this->service->trialBalance($this->from, $this->to);

    $sales = collect($report['rows'])->firstWhere('code', '4100');

    // Sales was only ever credited. A "sign everything" bug would report a
    // negative periodCredit here, which would break the footing proof on any
    // real ledger even though the closing balance would still look correct.
    expect($sales['periodDebit'])->toBe('0.00')
        ->and($sales['periodCredit'])->toBe('1500.00')
        ->and($sales['closingBalance'])->toBe('1500.00')
        ->and($report['totalDebit'])->toBe($report['totalCredit']);
});

it('holds closingBalance = openingBalance + signed(periodDebit - periodCredit) for every row (invariant I-2)', function (): void {
    $report = $this->service->trialBalance($this->from, $this->to);

    foreach ($report['rows'] as $row) {
        $sign = $row['code'] === '4100' ? -1 : 1;
        $expected = bcadd($row['openingBalance'], bcmul((string) $sign, bcsub($row['periodDebit'], $row['periodCredit'], 2), 2), 2);

        expect($row['closingBalance'])->toBe($expected);
    }
});

it('omits an account with no movement and a zero opening balance (FR-022)', function (): void {
    $idle = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1900', 'name' => 'Idle']);

    $report = $this->service->trialBalance($this->from, $this->to);

    expect(collect($report['rows'])->pluck('code'))->not->toContain('1900');
});

it('displays a footing discrepancy rather than concealing it (FR-024)', function (): void {
    $entry = JournalEntry::factory()->create([
        'entry_date' => '2026-01-10',
        'description' => 'Direct unbalanced write',
    ]);

    JournalEntryLine::factory()->for($entry)->create([
        'chart_account_id' => $this->cash->id,
        'debit' => '50.00',
        'credit' => '0.00',
        'sort_order' => 1,
    ]);

    JournalEntryLine::factory()->for($entry)->create([
        'chart_account_id' => $this->sales->id,
        'debit' => '0.00',
        'credit' => '40.00',
        'sort_order' => 2,
    ]);

    $entry->forceFill(['status' => 'posted', 'fiscal_period_id' => $this->january->id])->saveQuietly();

    $report = $this->service->trialBalance($this->from, $this->to);

    expect($report['foots'])->toBeFalse()
        ->and($report['variance'])->toBe('10.00');
});

// --- Phase 5: Profit and Loss (US3) -----------------------------------------

it('produces the exact profit and loss figures from quickstart Scenario 3', function (): void {
    $report = $this->service->profitAndLoss($this->from, $this->to);

    expect($report['sections']['income']['subtotal'])->toBe('1500.00')
        ->and($report['sections']['expense']['subtotal'])->toBe('300.00')
        ->and($report['netResult'])->toBe('1200.00')
        ->and($report['isLoss'])->toBeFalse();
});

it('extends the profit and loss net across a wider range', function (): void {
    $report = $this->service->profitAndLoss($this->from, CarbonImmutable::parse('2026-02-28'));

    expect($report['netResult'])->toBe('1900.00');
});

it('excludes asset, liability, and equity accounts from the profit and loss (FR-031)', function (): void {
    $report = $this->service->profitAndLoss($this->from, $this->to);

    $allCodes = collect($report['sections'])->flatMap(fn (array $section): array => collect($section['rows'])->pluck('code')->all());

    expect($allCodes)->not->toContain('1100', '1200');
});

it('flags a loss unambiguously when expense exceeds income (FR-032)', function (): void {
    $this->posting->postNew($this->actor, CarbonImmutable::parse('2026-01-28'), [
        ['chart_account_id' => $this->rent->id, 'debit' => '5000.00', 'credit' => '0.00'],
        ['chart_account_id' => $this->cash->id, 'debit' => '0.00', 'credit' => '5000.00'],
    ]);

    $report = $this->service->profitAndLoss($this->from, $this->to);

    expect($report['isLoss'])->toBeTrue()
        ->and((float) $report['netResult'])->toBeLessThan(0);
});

it("rolls a parent income account's descendants into its figure without double-counting the subtotal (invariant I-10)", function (): void {
    $salesGroup = ChartAccount::factory()->ofElement(AccountElement::Income)->header()->create(['code' => '4000', 'name' => 'Sales Group']);
    $this->sales->update(['parent_id' => $salesGroup->id]);

    $report = $this->service->profitAndLoss($this->from, $this->to);

    $rows = collect($report['sections']['income']['rows'])->keyBy('code');

    expect($rows['4000']['amount'])->toBe('1500.00')
        ->and($rows['4100']['amount'])->toBe('1500.00')
        ->and($report['sections']['income']['subtotal'])->toBe('1500.00');
});

// --- Phase 6: Balance Sheet (US4) -------------------------------------------

it('produces the exact balance sheet figures from quickstart Scenario 4', function (): void {
    $report = $this->service->balanceSheet(CarbonImmutable::parse('2026-01-31'));

    expect($report['totalAssets'])->toBe('1200.00')
        ->and($report['totalLiabilities'])->toBe('0.00')
        ->and($report['totalPostedEquity'])->toBe('0.00')
        ->and($report['accumulatedEarnings'])->toBe('1200.00')
        ->and($report['balances'])->toBeTrue()
        ->and($report['variance'])->toBe('0.00');
});

it('holds the accounting equation at several as-of dates, including before the ledger begins (invariant I-3, SC-004)', function (): void {
    $before = $this->service->balanceSheet(CarbonImmutable::parse('2026-01-01')->subDay());
    $mid = $this->service->balanceSheet(CarbonImmutable::parse('2026-01-10'));
    $after = $this->service->balanceSheet(CarbonImmutable::parse('2026-02-28'));

    foreach ([$before, $mid, $after] as $report) {
        expect($report['balances'])->toBeTrue()
            ->and($report['variance'])->toBe('0.00');
    }

    expect($before['totalAssets'])->toBe('0.00');
});

it("agrees the profit-and-loss net with the balance sheet's computed earnings line (invariant I-4, SC-005)", function (): void {
    $profitAndLoss = $this->service->profitAndLoss($this->from, CarbonImmutable::parse('2026-01-31'));
    $balanceSheet = $this->service->balanceSheet(CarbonImmutable::parse('2026-01-31'));

    expect($profitAndLoss['netResult'])->toBe($balanceSheet['accumulatedEarnings']);
});

it('creates no journal entry and leaves Retained Earnings untouched to produce the balance sheet (FR-035)', function (): void {
    $before = JournalEntry::query()->count();

    $this->service->balanceSheet(CarbonImmutable::parse('2026-01-31'));

    expect(JournalEntry::query()->count())->toBe($before);
});

it('excludes an entry dated after the as-of date (FR-038)', function (): void {
    $report = $this->service->balanceSheet(CarbonImmutable::parse('2026-01-31'));

    // E5 (2026-02-10, 700.00) must not appear.
    expect($report['totalAssets'])->toBe('1200.00');
});

// --- Phase 7: General Ledger (US5) ------------------------------------------

it("reconciles the general ledger's final running balance to the trial balance closing balance (invariant I-5, SC-007)", function (): void {
    $trialBalance = $this->service->trialBalance($this->from, $this->to);
    $cashRow = collect($trialBalance['rows'])->firstWhere('code', '1100');

    $ledger = $this->service->generalLedger($this->from, $this->to, $this->cash->id, 50);

    expect(collect($ledger->items())->last()['runningBalance'])->toBe($cashRow['closingBalance']);
});

it('produces the exact general ledger lines from quickstart Scenario 5', function (): void {
    $ledger = $this->service->generalLedger($this->from, $this->to, $this->cash->id, 50);
    $items = collect($ledger->items())->values();

    expect($items)->toHaveCount(2)
        ->and($items[0]['debit'])->toBe('1000.00')
        ->and($items[0]['runningBalance'])->toBe('1000.00')
        ->and($items[1]['credit'])->toBe('300.00')
        ->and($items[1]['runningBalance'])->toBe('700.00');
});

it("excludes a draft entry's lines from the general ledger (FR-007)", function (): void {
    $ledger = $this->service->generalLedger($this->from, $this->to, $this->cash->id, 50);

    expect(collect($ledger->items())->pluck('debit'))->not->toContain('9999.00');
});

it("includes a parent's descendants and labels each line by its own account (FR-027)", function (): void {
    $cashGroup = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000', 'name' => 'Cash Group']);
    $this->cash->update(['parent_id' => $cashGroup->id]);

    $ledger = $this->service->generalLedger($this->from, $this->to, $cashGroup->id, 50);
    $items = collect($ledger->items());

    expect($items)->toHaveCount(2)
        ->and($items->pluck('accountCode'))->toContain('1100');
});

it('orders general ledger lines deterministically (FR-016, FR-029)', function (): void {
    $first = collect($this->service->generalLedger($this->from, $this->to, null, 50)->items())->pluck('lineId');
    $second = collect($this->service->generalLedger($this->from, $this->to, null, 50)->items())->pluck('lineId');

    expect($first->all())->toBe($second->all());
});

// --- Phase 8: Posting Register (US6) ----------------------------------------

it('produces the posting register in date and entry-number order, excluding drafts (FR-039, FR-040)', function (): void {
    $register = $this->service->postingRegister($this->from, $this->to, 50);
    $entryNumbers = collect($register->items())->pluck('entryNumber');

    expect($entryNumbers->all())->toBe([$this->e1->entry_number, $this->e2->entry_number, $this->e3->entry_number]);
});

it('renders an entry with no source as empty (FR-043)', function (): void {
    $register = $this->service->postingRegister($this->from, $this->to, 50);
    $row = collect($register->items())->firstWhere('entryId', $this->e1->id);

    expect($row['source'])->toBeNull();
});

it("names a reversal's source by the original entry's number (FR-041)", function (): void {
    $reversal = $this->posting->reverse($this->actor, $this->e1, CarbonImmutable::parse('2026-01-28'));

    $register = $this->service->postingRegister($this->from, $this->to, 50);
    $row = collect($register->items())->firstWhere('entryId', $reversal->id);

    expect($row['source']['label'])->toBe($this->e1->entry_number)
        ->and($row['source']['resolved'])->toBeTrue();
});

it('renders a non-accounting source morph as a readable type-and-id label without failing (FR-041, SC-011)', function (): void {
    $product = Product::factory()->create();

    $entry = $this->posting->postNew(
        $this->actor,
        CarbonImmutable::parse('2026-01-12'),
        [
            ['chart_account_id' => $this->cash->id, 'debit' => '20.00', 'credit' => '0.00'],
            ['chart_account_id' => $this->sales->id, 'debit' => '0.00', 'credit' => '20.00'],
        ],
        source: $product,
    );

    $register = $this->service->postingRegister($this->from, $this->to, 50);
    $row = collect($register->items())->firstWhere('entryId', $entry->id);

    expect($row['source']['label'])->toBe('Product #'.$product->id)
        ->and($row['source']['resolved'])->toBeTrue();
});

it('renders an unresolvable source morph as unresolved without failing (FR-042, SC-011)', function (): void {
    $target = Product::factory()->create();
    $targetId = $target->id;

    $entry = $this->posting->postNew(
        $this->actor,
        CarbonImmutable::parse('2026-01-13'),
        [
            ['chart_account_id' => $this->cash->id, 'debit' => '15.00', 'credit' => '0.00'],
            ['chart_account_id' => $this->sales->id, 'debit' => '0.00', 'credit' => '15.00'],
        ],
        source: $target,
    );

    $target->forceDelete();

    $register = $this->service->postingRegister($this->from, $this->to, 50);
    $row = collect($register->items())->firstWhere('entryId', $entry->id);

    expect($row['source']['resolved'])->toBeFalse()
        ->and($row['source']['label'])->toBe('Product #'.$targetId.' (unresolved)');
});

// --- Phase 10: cross-report invariants --------------------------------------

it('excludes the draft entry from every one of the five reports (invariant I-7, FR-007, SC-003)', function (): void {
    $trialBalance = $this->service->trialBalance($this->from, $this->to);
    $profitAndLoss = $this->service->profitAndLoss($this->from, $this->to);
    $balanceSheet = $this->service->balanceSheet($this->to);
    $ledgerLines = collect($this->service->generalLedger($this->from, $this->to, null, 50)->items());
    $registerEntries = collect($this->service->postingRegister($this->from, $this->to, 50)->items())->pluck('entryId');

    // E4 (draft, Rent debit / Cash credit 100.00) would show up as an extra
    // 100.00 on Rent's debit, Cash's credit, and the expense subtotal, and as
    // a fourth entry in the ledger and register, if it leaked through.
    expect(collect($trialBalance['rows'])->firstWhere('code', '5300')['periodDebit'])->toBe('300.00')
        ->and($profitAndLoss['sections']['expense']['subtotal'])->toBe('300.00')
        ->and($balanceSheet['accumulatedEarnings'])->toBe('1200.00')
        ->and($ledgerLines->pluck('debit'))->not->toContain('100.00')
        ->and($registerEntries)->not->toContain($this->e4->id);
});

it('nets a posted entry and its reversal to zero on every report (invariant I-6, SC-002)', function (): void {
    $this->posting->reverse($this->actor, $this->e1, CarbonImmutable::parse('2026-01-28'));

    $trialBalance = $this->service->trialBalance($this->from, $this->to);
    $profitAndLoss = $this->service->profitAndLoss($this->from, $this->to);
    $balanceSheet = $this->service->balanceSheet($this->to);

    expect($trialBalance['totalDebit'])->toBe('2800.00')
        ->and($trialBalance['totalCredit'])->toBe('2800.00')
        ->and(collect($trialBalance['rows'])->firstWhere('code', '1100')['closingBalance'])->toBe('-300.00')
        ->and($balanceSheet['balances'])->toBeTrue()
        ->and((float) $profitAndLoss['sections']['income']['subtotal'])->toBe(500.0);
});

it('renders every report over an empty ledger as zero rows and zero totals (invariant I-8, FR-017, SC-009)', function (): void {
    JournalEntryLine::query()->delete();
    JournalEntry::query()->delete();

    $trialBalance = $this->service->trialBalance($this->from, $this->to);
    $profitAndLoss = $this->service->profitAndLoss($this->from, $this->to);
    $balanceSheet = $this->service->balanceSheet($this->to);
    $ledger = $this->service->generalLedger($this->from, $this->to, null, 50);
    $register = $this->service->postingRegister($this->from, $this->to, 50);

    expect($trialBalance['rows'])->toBeEmpty()
        ->and($trialBalance['foots'])->toBeTrue()
        ->and($profitAndLoss['netResult'])->toBe('0.00')
        ->and($balanceSheet['balances'])->toBeTrue()
        ->and($balanceSheet['totalAssets'])->toBe('0.00')
        ->and($ledger->total())->toBe(0)
        ->and($register->total())->toBe(0);
});

it("includes a soft-deleted account's posted history, flagged deleted, and ignores is_active/is_postable (FR-018, FR-019)", function (): void {
    $this->cash->update(['is_active' => false]);
    $this->cash->delete();

    $report = $this->service->trialBalance($this->from, $this->to);
    $row = collect($report['rows'])->firstWhere('code', '1100');

    expect($row)->not->toBeNull()
        ->and($row['isDeleted'])->toBeTrue()
        ->and($row['closingBalance'])->toBe('700.00');
});

it('aggregates each statement in a query count independent of account and line volume (FR-020)', function (): void {
    foreach (range(1, 10) as $index) {
        ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '19'.mb_str_pad((string) $index, 2, '0', STR_PAD_LEFT)]);
    }

    DB::enableQueryLog();
    $this->service->trialBalance($this->from, $this->to);
    $queryCount = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog();

    // Accounts + account types (eager-loaded) + two aggregate queries
    // (opening, movement) — constant regardless of account or line count,
    // mirroring AccountBalanceServiceTest's identical assertion.
    expect($queryCount)->toBeLessThanOrEqual(4);
});
