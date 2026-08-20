<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\DashboardRole;
use App\Enums\JournalEntryStatus;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\AccountBalanceService;
use App\Services\Accounting\Exceptions\ClosedFiscalPeriod;
use App\Services\Accounting\Exceptions\EntryAlreadyReversed;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AccountingPermissionSeeder)->run();

    $this->service = app(JournalPostingService::class);

    $this->accountant = User::factory()->create();
    $this->accountant->assignRole(DashboardRole::Accountant->value);

    $this->chief = User::factory()->create();
    $this->chief->assignRole(DashboardRole::ChiefAccountant->value);

    $this->actingAs($this->chief);

    $this->period = FiscalPeriod::factory()->forMonth(CarbonImmutable::now())->create();
    $this->cash = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1100']);
    $this->sales = ChartAccount::factory()->ofElement(AccountElement::Income)->create(['code' => '4100']);

    $this->postEntry = fn (string $amount = '100.00', ?CarbonImmutable $date = null): JournalEntry => $this->service->postNew(
        $this->chief,
        $date ?? CarbonImmutable::now(),
        [
            ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => $amount, 'credit' => '0.00'],
            ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => $amount],
        ],
    );
});

it('creates a posted mirror entry linked to the original through the source morph', function (): void {
    $original = ($this->postEntry)();

    $reversal = $this->service->reverse($this->chief, $original);

    expect($reversal->status)->toBe(JournalEntryStatus::Posted)
        ->and($reversal->source_type)->toBe(JournalEntry::class)
        ->and($reversal->source_id)->toBe($original->getKey())
        ->and($reversal->getKey())->not->toBe($original->getKey());
});

it('swaps debits and credits line for line', function (): void {
    $original = ($this->postEntry)('250.00');

    $reversal = $this->service->reverse($this->chief, $original);

    $originalLines = $original->lines()->get();
    $reversalLines = $reversal->lines()->get();

    expect($reversalLines)->toHaveCount(2);

    foreach ($originalLines as $index => $line) {
        expect($reversalLines[$index]->chart_account_id)->toBe($line->chart_account_id)
            ->and($reversalLines[$index]->debit)->toBe($line->credit)
            ->and($reversalLines[$index]->credit)->toBe($line->debit);
    }
});

it('describes itself by the entry it reverses', function (): void {
    $original = ($this->postEntry)();

    $reversal = $this->service->reverse($this->chief, $original);

    expect($reversal->description)->toBe('Reversal of '.$original->entry_number);
});

it('accepts a caller-supplied description', function (): void {
    $original = ($this->postEntry)();

    $reversal = $this->service->reverse($this->chief, $original, null, 'Wrong account, corrected');

    expect($reversal->description)->toBe('Wrong account, corrected');
});

it('nets to zero against the original', function (): void {
    $original = ($this->postEntry)('400.00');
    $balances = app(AccountBalanceService::class);

    expect($balances->balanceFor($this->cash))->toBe('400.00');

    $this->service->reverse($this->chief, $original);

    expect($balances->balanceFor($this->cash))->toBe('0.00')
        ->and($balances->balanceFor($this->sales))->toBe('0.00');
});

it('refuses to reverse the same entry twice, naming the existing reversal', function (): void {
    $original = ($this->postEntry)();
    $first = $this->service->reverse($this->chief, $original);

    expect(fn (): JournalEntry => $this->service->reverse($this->chief, $original->fresh()))
        ->toThrow(EntryAlreadyReversed::class, (string) $first->entry_number);

    expect(JournalEntry::query()->count())->toBe(2);
});

it('refuses to reverse a draft', function (): void {
    $draft = $this->service->draft($this->chief, CarbonImmutable::now(), [
        ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '10.00', 'credit' => '0.00'],
        ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '10.00'],
    ]);

    expect(fn (): JournalEntry => $this->service->reverse($this->chief, $draft))
        ->toThrow(AuthorizationException::class);
});

it('lands the reversal in a later open period when given a later date', function (): void {
    $lastMonth = CarbonImmutable::now()->subMonth();
    $earlierPeriod = FiscalPeriod::factory()->forMonth($lastMonth)->create();

    $original = ($this->postEntry)('100.00', $lastMonth->startOfMonth()->addDay());
    expect($original->fiscal_period_id)->toBe($earlierPeriod->getKey());

    $reversal = $this->service->reverse($this->chief, $original, CarbonImmutable::now());

    expect($reversal->fiscal_period_id)->toBe($this->period->getKey());
});

it('refuses a reversal dated into a closed period', function (): void {
    $lastMonth = CarbonImmutable::now()->subMonth();
    $earlierPeriod = FiscalPeriod::factory()->forMonth($lastMonth)->create();

    $original = ($this->postEntry)('100.00', $lastMonth->startOfMonth()->addDay());
    $earlierPeriod->update(['is_closed' => true]);

    expect(fn (): JournalEntry => $this->service->reverse($this->chief, $original))
        ->toThrow(ClosedFiscalPeriod::class, (string) $earlierPeriod->name);

    expect(JournalEntry::query()->count())->toBe(1);
});

it('refuses a reversal of a reversal', function (): void {
    $original = ($this->postEntry)();
    $reversal = $this->service->reverse($this->chief, $original);

    // The second reversal is refused because the first already points at the
    // original — the check needs no special "is this a reversal?" rule.
    expect(fn (): JournalEntry => $this->service->reverse($this->chief, $original->fresh()))
        ->toThrow(EntryAlreadyReversed::class);

    // Reversing the reversal itself is a distinct, legitimate operation.
    $second = $this->service->reverse($this->chief, $reversal);

    expect($second->source_id)->toBe($reversal->getKey());
});

it('refuses an accountant who lacks the reverse permission', function (): void {
    $original = ($this->postEntry)();

    expect(fn (): JournalEntry => $this->service->reverse($this->accountant, $original))
        ->toThrow(AuthorizationException::class);

    expect(JournalEntry::query()->count())->toBe(1);
});

it('does not require the post or create permission separately', function (): void {
    $reverserOnly = User::factory()->create();
    $reverserOnly->givePermissionTo('accounting.journal-entry.reverse');
    $reverserOnly->assignRole(DashboardRole::Reviewer->value);

    $original = ($this->postEntry)();

    $reversal = $this->service->reverse($reverserOnly, $original);

    expect($reversal->status)->toBe(JournalEntryStatus::Posted);
});

it('leaves the original untouched', function (): void {
    $original = ($this->postEntry)();
    $before = $original->fresh();

    $this->service->reverse($this->chief, $original);

    $after = $original->fresh();

    expect($after->status)->toBe($before->status)
        ->and($after->entry_date->toDateString())->toBe($before->entry_date->toDateString())
        ->and($after->description)->toBe($before->description)
        ->and($after->fiscal_period_id)->toBe($before->fiscal_period_id);
});

it('writes audit entries against both the original and the reversal', function (): void {
    $original = ($this->postEntry)();

    $reversal = $this->service->reverse($this->chief, $original);

    $this->assertDatabaseHas('activity_log', [
        'description' => 'accounting.journal_entry.reversed',
        'subject_id' => $original->getKey(),
        'causer_id' => $this->chief->getKey(),
    ]);

    $this->assertDatabaseHas('activity_log', [
        'description' => 'accounting.journal_entry.posted',
        'subject_id' => $reversal->getKey(),
    ]);
});

it('reports no reversal for an entry that has only a draft pointing at it', function (): void {
    $original = ($this->postEntry)();

    $this->service->draft($this->chief, CarbonImmutable::now(), [
        ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '5.00', 'credit' => '0.00'],
        ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '5.00'],
    ], null, $original);

    expect($original->fresh()->reversal)->toBeNull();

    // A draft is not in the ledger, so it must not block a real reversal.
    expect($this->service->reverse($this->chief, $original->fresh())->status)
        ->toBe(JournalEntryStatus::Posted);
});

it('refuses to reverse an entry a second time even after the first reversal is itself reversed', function (): void {
    $original = ($this->postEntry)();
    $reversal = $this->service->reverse($this->chief, $original);
    $this->service->reverse($this->chief, $reversal);

    expect(fn (): JournalEntry => $this->service->reverse($this->chief, $original->fresh()))
        ->toThrow(EntryAlreadyReversed::class);
});

it('is refused entirely when the actor cannot reverse and the entry is a draft', function (): void {
    $draft = $this->service->draft($this->chief, CarbonImmutable::now(), [
        ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '10.00', 'credit' => '0.00'],
        ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '10.00'],
    ]);

    expect(fn (): JournalEntry => $this->service->reverse($this->accountant, $draft))
        ->toThrow(AuthorizationException::class);
});

it('keeps the ledger balanced overall after a reversal', function (): void {
    ($this->postEntry)('123.45');
    $original = ($this->postEntry)('67.89');

    $this->service->reverse($this->chief, $original);

    $balances = app(AccountBalanceService::class);

    expect($balances->balanceFor($this->cash))->toBe('123.45')
        ->and($balances->balanceFor($this->sales))->toBe('123.45');
});

it('reverses correctly when the original has more than two lines', function (): void {
    $other = ChartAccount::factory()->ofElement(AccountElement::Income)->create(['code' => '4200']);

    $original = $this->service->postNew($this->chief, CarbonImmutable::now(), [
        ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '300.00', 'credit' => '0.00'],
        ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '200.00'],
        ['chart_account_id' => (int) $other->getKey(), 'debit' => '0.00', 'credit' => '100.00'],
    ]);

    $reversal = $this->service->reverse($this->chief, $original);
    $balances = app(AccountBalanceService::class);

    expect($reversal->lines()->count())->toBe(3)
        ->and($balances->balanceFor($this->cash))->toBe('0.00')
        ->and($balances->balanceFor($this->sales))->toBe('0.00')
        ->and($balances->balanceFor($other))->toBe('0.00');
});
