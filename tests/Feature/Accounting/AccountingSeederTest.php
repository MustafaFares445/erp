<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\DashboardRole;
use App\Enums\JournalEntryStatus;
use App\Models\AccountType;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingDemoSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('the chart of accounts seeder', function (): void {
    it('seeds exactly one account type per accounting element (FR-002)', function (): void {
        (new ChartOfAccountsSeeder)->run();

        expect(AccountType::query()->count())->toBe(5)
            ->and(AccountType::query()->pluck('name')->map(fn (AccountElement $element): string => $element->value)->all())
            ->toEqualCanonicalizing(AccountElement::values());

        foreach (AccountType::query()->get() as $type) {
            expect($type->normal_balance)->toBe($type->name->normalBalance());
        }
    });

    it('makes every header non-postable and every leaf postable (FR-007)', function (): void {
        (new ChartOfAccountsSeeder)->run();

        $accounts = ChartAccount::query()->withCount('children')->get();

        expect($accounts)->not->toBeEmpty();

        foreach ($accounts as $account) {
            expect($account->is_postable)->toBe($account->children_count === 0)
                ->and($account->is_active)->toBeTrue();
        }
    });

    it('is idempotent', function (): void {
        (new ChartOfAccountsSeeder)->run();

        $types = AccountType::query()->count();
        $accounts = ChartAccount::query()->count();

        (new ChartOfAccountsSeeder)->run();

        expect(AccountType::query()->count())->toBe($types)
            ->and(ChartAccount::query()->count())->toBe($accounts);
    });

    it('leaves an edited account alone on a re-run', function (): void {
        (new ChartOfAccountsSeeder)->run();

        $cash = ChartAccount::query()->where('code', '1100')->sole();
        $cash->update(['name' => 'Renamed by an accountant', 'is_active' => false]);

        (new ChartOfAccountsSeeder)->run();

        expect($cash->refresh()->name)->toBe('Renamed by an accountant')
            ->and($cash->is_active)->toBeFalse();
    });
});

describe('the accounting demo seeder', function (): void {
    beforeEach(function (): void {
        (new AccountingDemoSeeder)->run();
    });

    it('seeds twelve consecutive monthly periods for the current year', function (): void {
        $periods = FiscalPeriod::query()->orderBy('starts_at')->get();

        expect($periods)->toHaveCount(12);

        $month = CarbonImmutable::now()->startOfYear();

        foreach ($periods as $index => $period) {
            $expected = $month->addMonthsNoOverflow($index);

            expect($period->name)->toBe($expected->format('F Y'))
                ->and($period->starts_at->toDateString())->toBe($expected->toDateString())
                ->and($period->ends_at->toDateString())->toBe($expected->endOfMonth()->toDateString());
        }

        // The oldest is closed so the Reopen action has a subject (FR-016).
        expect($periods->first()->is_closed)->toBeTrue()
            ->and($periods->skip(1)->pluck('is_closed')->unique()->all())->toBe([false]);
    });

    it('seeds posted entries, one reversal, and exactly one draft', function (): void {
        $entries = JournalEntry::query()->with('lines')->get();

        expect($entries->where('status', JournalEntryStatus::Draft))->toHaveCount(1)
            ->and($entries->where('status', JournalEntryStatus::Posted)->count())->toBeGreaterThanOrEqual(5);

        $reversal = $entries->firstWhere('source_type', JournalEntry::class);

        expect($reversal)->not->toBeNull()
            ->and($reversal->status)->toBe(JournalEntryStatus::Posted);

        // Every posted entry balances and carries a resolved period; the draft
        // carries neither obligation.
        foreach ($entries->where('status', JournalEntryStatus::Posted) as $entry) {
            expect($entry->fiscal_period_id)->not->toBeNull()
                ->and($entry->lines->sum(fn ($line): float => (float) $line->debit))
                ->toBe($entry->lines->sum(fn ($line): float => (float) $line->credit));
        }

        expect($entries->firstWhere('status', JournalEntryStatus::Draft)->fiscal_period_id)->toBeNull();
    });

    it('numbers the entries sequentially with no gaps', function (): void {
        $numbers = JournalEntry::query()->orderBy('id')->pluck('entry_number')->all();

        foreach ($numbers as $index => $number) {
            expect($number)->toBe(sprintf('JE-%06d', $index + 1));
        }
    });

    it('seeds a chief accountant and an accountant who can reach the dashboard', function (): void {
        $chief = User::query()->where('email', 'chief.accountant@ierp.com')->sole();
        $accountant = User::query()->where('email', 'accountant@ierp.com')->sole();

        expect($chief->hasRole(DashboardRole::ChiefAccountant->value))->toBeTrue()
            ->and($accountant->hasRole(DashboardRole::Accountant->value))->toBeTrue()
            ->and($accountant->can('reverse', JournalEntry::query()->where('status', JournalEntryStatus::Posted)->first()))
            ->toBeFalse();
    });

    it('is idempotent', function (): void {
        $entries = JournalEntry::query()->count();
        $periods = FiscalPeriod::query()->count();

        (new AccountingDemoSeeder)->run();

        expect(JournalEntry::query()->count())->toBe($entries)
            ->and(FiscalPeriod::query()->count())->toBe($periods);
    });
});
