<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\DashboardRole;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\AccountBalanceService;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->balances = app(AccountBalanceService::class);
    $this->posting = app(JournalPostingService::class);

    $this->chief = User::factory()->create();
    $this->chief->assignRole(DashboardRole::ChiefAccountant->value);
    $this->actingAs($this->chief);

    FiscalPeriod::factory()->create();

    $this->cash = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1100']);
    $this->sales = ChartAccount::factory()->ofElement(AccountElement::Income)->create(['code' => '4100']);

    $this->post = fn (ChartAccount $debit, ChartAccount $credit, string $amount) => $this->posting->postNew(
        $this->chief,
        CarbonImmutable::now(),
        [
            ['chart_account_id' => (int) $debit->getKey(), 'debit' => $amount, 'credit' => '0.00'],
            ['chart_account_id' => (int) $credit->getKey(), 'debit' => '0.00', 'credit' => $amount],
        ],
    );
});

it('reports zero for an account with no lines', function (): void {
    expect($this->balances->balanceFor($this->cash))->toBe('0.00');
});

it('reports a debit-normal account positive when debits exceed credits', function (): void {
    ($this->post)($this->cash, $this->sales, '500.00');

    expect($this->balances->balanceFor($this->cash))->toBe('500.00');
});

it('reports a credit-normal account positive when credits exceed debits', function (): void {
    ($this->post)($this->cash, $this->sales, '500.00');

    // The Income account was credited. Its normal balance is credit, so holding
    // that balance must read positive rather than -500.00 (FR-036).
    expect($this->balances->balanceFor($this->sales))->toBe('500.00');
});

it('reports a debit-normal account negative when credits exceed debits', function (): void {
    ($this->post)($this->sales, $this->cash, '120.00');

    expect($this->balances->balanceFor($this->cash))->toBe('-120.00');
});

it('accumulates across several postings', function (): void {
    ($this->post)($this->cash, $this->sales, '100.00');
    ($this->post)($this->cash, $this->sales, '250.50');
    ($this->post)($this->sales, $this->cash, '50.50');

    expect($this->balances->balanceFor($this->cash))->toBe('300.00')
        ->and($this->balances->balanceFor($this->sales))->toBe('300.00');
});

it('excludes draft lines', function (): void {
    ($this->post)($this->cash, $this->sales, '100.00');

    $this->posting->draft($this->chief, CarbonImmutable::now(), [
        ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '9999.00', 'credit' => '0.00'],
        ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '9999.00'],
    ]);

    expect($this->balances->balanceFor($this->cash))->toBe('100.00');
});

it('rolls descendants up into a parent balance', function (): void {
    $header = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000']);
    $this->cash->update(['parent_id' => $header->getKey()]);

    $bank = ChartAccount::factory()->ofElement(AccountElement::Asset)->create([
        'code' => '1110',
        'parent_id' => $header->getKey(),
    ]);

    ($this->post)($this->cash, $this->sales, '100.00');
    ($this->post)($bank, $this->sales, '250.00');

    expect($this->balances->balanceFor($header))->toBe('350.00')
        ->and($this->balances->balanceFor($header, includeDescendants: false))->toBe('0.00');
});

it('rolls up through more than one level', function (): void {
    $root = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000']);
    $mid = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create([
        'code' => '1150',
        'parent_id' => $root->getKey(),
    ]);
    $this->cash->update(['parent_id' => $mid->getKey()]);

    ($this->post)($this->cash, $this->sales, '75.25');

    expect($this->balances->balanceFor($root))->toBe('75.25')
        ->and($this->balances->balanceFor($mid))->toBe('75.25');
});

it('reports every balance in balancesForAll', function (): void {
    ($this->post)($this->cash, $this->sales, '640.00');

    $all = $this->balances->balancesForAll();

    expect($all[(int) $this->cash->getKey()])->toBe('640.00')
        ->and($all[(int) $this->sales->getKey()])->toBe('640.00');
});

it('agrees with balanceFor for every account', function (): void {
    $header = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000']);
    $this->cash->update(['parent_id' => $header->getKey()]);
    ($this->post)($this->cash, $this->sales, '33.33');

    $all = $this->balances->balancesForAll();

    foreach (ChartAccount::query()->with('accountType')->get() as $account) {
        expect($all[(int) $account->getKey()])->toBe($this->balances->balanceFor($account));
    }
});

it('does not run a query per account in balancesForAll', function (): void {
    $header = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000']);

    foreach (range(1, 12) as $index) {
        ChartAccount::factory()->ofElement(AccountElement::Asset)->create([
            'code' => '12'.mb_str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'parent_id' => $header->getKey(),
        ]);
    }

    ($this->post)($this->cash, $this->sales, '10.00');

    DB::enableQueryLog();
    $this->balances->balancesForAll();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Accounts + account types + one grouped aggregate. The roll-up is an
    // in-memory tree walk, so adding accounts must not add queries.
    expect($queryCount)->toBeLessThanOrEqual(4);
});

it('lists an accounts posted ledger lines oldest first', function (): void {
    $first = ($this->post)($this->cash, $this->sales, '10.00');
    $second = ($this->post)($this->cash, $this->sales, '20.00');

    $ledger = $this->balances->ledgerFor($this->cash);

    expect($ledger)->toHaveCount(2)
        ->and($ledger->first()?->journal_entry_id)->toBe($first->getKey())
        ->and($ledger->last()?->journal_entry_id)->toBe($second->getKey());
});

it('excludes draft lines from the ledger', function (): void {
    ($this->post)($this->cash, $this->sales, '10.00');

    $this->posting->draft($this->chief, CarbonImmutable::now(), [
        ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '99.00', 'credit' => '0.00'],
        ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '99.00'],
    ]);

    expect($this->balances->ledgerFor($this->cash))->toHaveCount(1);
});

it('excludes lines belonging to other accounts from the ledger', function (): void {
    ($this->post)($this->cash, $this->sales, '10.00');

    expect($this->balances->ledgerFor($this->cash))->toHaveCount(1)
        ->and($this->balances->ledgerFor($this->sales))->toHaveCount(1);
});

it('computes a running balance in ledger order', function (): void {
    ($this->post)($this->cash, $this->sales, '100.00');
    ($this->post)($this->cash, $this->sales, '50.00');
    ($this->post)($this->sales, $this->cash, '30.00');

    $ledger = $this->balances->ledgerFor($this->cash);
    $running = $this->balances->runningBalances($this->cash, $ledger);

    expect(array_values($running))->toBe(['100.00', '150.00', '120.00'])
        ->and(end($running))->toBe($this->balances->balanceFor($this->cash, includeDescendants: false));
});

it('signs the running balance by the account normal balance', function (): void {
    ($this->post)($this->cash, $this->sales, '100.00');
    ($this->post)($this->cash, $this->sales, '25.00');

    $ledger = $this->balances->ledgerFor($this->sales);
    $running = $this->balances->runningBalances($this->sales, $ledger);

    expect(array_values($running))->toBe(['100.00', '125.00']);
});

it('sums exact decimals without float drift', function (): void {
    foreach (range(1, 10) as $ignored) {
        ($this->post)($this->cash, $this->sales, '0.10');
    }

    expect($this->balances->balanceFor($this->cash))->toBe('1.00');
});

it('reports own-only balances in balancesForAll when descendants are excluded', function (): void {
    $header = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000']);
    $this->cash->update(['parent_id' => $header->getKey()]);

    ($this->post)($this->cash, $this->sales, '60.00');

    $rolledUp = $this->balances->balancesForAll();
    $ownOnly = $this->balances->balancesForAll(includeDescendants: false);

    expect($rolledUp[$header->id])->toBe('60.00')
        ->and($ownOnly[$header->id])->toBe('0.00')
        ->and($ownOnly[$this->cash->id])->toBe('60.00');
});

it('reports a zero balance for an account with no ids to sum', function (): void {
    // `selfAndDescendantIds()` always contains at least the account itself, so the
    // empty-set branch is reachable only through the own-only path on a brand new
    // account — proven here rather than assumed, because a wrong answer would be
    // silently plausible.
    $fresh = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1900']);

    $emptySum = new ReflectionMethod($this->balances, 'netMinorUnitsFor');

    expect($emptySum->invoke($this->balances, []))->toBe(0)
        ->and($this->balances->balanceFor($fresh, includeDescendants: false))->toBe('0.00');
});

it('does not recurse forever when a direct database write introduces a cycle', function (): void {
    $parent = ChartAccount::factory()->ofElement(AccountElement::Asset)->header()->create(['code' => '1000']);
    $child = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1010', 'parent_id' => $parent->id]);

    ($this->post)($child, $this->sales, '25.00');

    // ChartOfAccountService refuses this, so only a direct write can produce it —
    // exactly the situation where an infinite loop is hardest to diagnose.
    DB::table('chart_accounts')->where('id', $parent->id)->update(['parent_id' => $child->id]);

    // Each account is counted once despite the loop.
    expect($this->balances->balancesForAll()[$parent->id])->toBe('25.00')
        ->and($this->balances->balanceFor($parent->refresh()))->toBe('25.00');
});
