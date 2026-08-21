<?php

declare(strict_types=1);

use App\Enums\AccountElement;
use App\Enums\DashboardRole;
use App\Enums\JournalEntryStatus;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\Exceptions\ClosedFiscalPeriod;
use App\Services\Accounting\Exceptions\InvalidJournalEntryLine;
use App\Services\Accounting\Exceptions\NoFiscalPeriodForDate;
use App\Services\Accounting\Exceptions\PostedEntryIsImmutable;
use App\Services\Accounting\Exceptions\UnbalancedJournalEntry;
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

    $this->actingAs($this->accountant);

    $this->period = FiscalPeriod::factory()->create();
    $this->cash = ChartAccount::factory()->ofElement(AccountElement::Asset)->create(['code' => '1100']);
    $this->sales = ChartAccount::factory()->ofElement(AccountElement::Income)->create(['code' => '4100']);
});

/** @return list<array{chart_account_id: int, debit: string, credit: string}> */
function balancedLines(string $amount = '100.00'): array
{
    return [
        ['chart_account_id' => (int) test()->cash->getKey(), 'debit' => $amount, 'credit' => '0.00'],
        ['chart_account_id' => (int) test()->sales->getKey(), 'debit' => '0.00', 'credit' => $amount],
    ];
}

describe('draft', function (): void {
    it('creates an unposted entry with a generated number and no fiscal period', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), balancedLines());

        expect($entry->status)->toBe(JournalEntryStatus::Draft)
            ->and($entry->entry_number)->toBe('JE-000001')
            ->and($entry->fiscal_period_id)->toBeNull()
            ->and($entry->lines)->toHaveCount(2)
            ->and($entry->created_by)->toBe($this->accountant->getKey());
    });

    it('numbers entries sequentially', function (): void {
        $first = $this->service->draft($this->accountant, CarbonImmutable::now(), balancedLines());
        $second = $this->service->draft($this->accountant, CarbonImmutable::now(), balancedLines());

        expect($first->entry_number)->toBe('JE-000001')
            ->and($second->entry_number)->toBe('JE-000002');
    });

    it('stamps sort order in the given line order', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), balancedLines());

        expect($entry->lines->pluck('sort_order')->all())->toBe([1, 2]);
    });

    it('accepts an unbalanced draft, because balance is checked at posting', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), [
            ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '100.00', 'credit' => '0.00'],
            ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '90.00'],
        ]);

        expect($entry->status)->toBe(JournalEntryStatus::Draft);
    });

    it('refuses a user without the manage permission', function (): void {
        $reviewer = User::factory()->create();
        $reviewer->assignRole(DashboardRole::Reviewer->value);

        expect(fn (): JournalEntry => $this->service->draft($reviewer, CarbonImmutable::now(), balancedLines()))
            ->toThrow(AuthorizationException::class);
    });
});

describe('post', function (): void {
    it('posts a balanced entry and stamps the resolved fiscal period', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), balancedLines());

        $posted = $this->service->post($this->accountant, $entry);

        expect($posted->status)->toBe(JournalEntryStatus::Posted)
            ->and($posted->fiscal_period_id)->toBe($this->period->getKey());
    });

    it('refuses an entry whose debits do not equal its credits, naming both totals', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), [
            ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '100.00', 'credit' => '0.00'],
            ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '90.00'],
        ]);

        expect(fn (): JournalEntry => $this->service->post($this->accountant, $entry))
            ->toThrow(UnbalancedJournalEntry::class, 'Debits total 100.00 but credits total 90.00');

        expect($entry->fresh()->status)->toBe(JournalEntryStatus::Draft);
    });

    it('accepts amounts that balance only under exact decimal arithmetic', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), [
            ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '33.33', 'credit' => '0.00'],
            ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '33.33'],
        ]);

        expect($this->service->post($this->accountant, $entry)->status)->toBe(JournalEntryStatus::Posted);
    });

    it('rejects a one-cent imbalance', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), [
            ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '33.33', 'credit' => '0.00'],
            ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '33.34'],
        ]);

        expect(fn (): JournalEntry => $this->service->post($this->accountant, $entry))
            ->toThrow(UnbalancedJournalEntry::class);
    });

    it('refuses an entry with fewer than two lines', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), [
            ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '100.00', 'credit' => '0.00'],
        ]);

        expect(fn (): JournalEntry => $this->service->post($this->accountant, $entry))
            ->toThrow(UnbalancedJournalEntry::class, 'at least two lines');
    });

    it('refuses a line carrying both a debit and a credit', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), [
            ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '100.00', 'credit' => '100.00'],
            ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '100.00'],
        ]);

        expect(fn (): JournalEntry => $this->service->post($this->accountant, $entry))
            ->toThrow(InvalidJournalEntryLine::class, 'Line 1 carries both');
    });

    it('refuses a line carrying neither a debit nor a credit', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), [
            ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '100.00', 'credit' => '0.00'],
            ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '100.00'],
            ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '0.00'],
        ]);

        expect(fn (): JournalEntry => $this->service->post($this->accountant, $entry))
            ->toThrow(InvalidJournalEntryLine::class, 'Line 3 carries neither');
    });

    it('refuses a line targeting a non-postable account', function (): void {
        $header = ChartAccount::factory()->header()->create(['code' => '1000']);

        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), [
            ['chart_account_id' => (int) $header->getKey(), 'debit' => '100.00', 'credit' => '0.00'],
            ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '100.00'],
        ]);

        expect(fn (): JournalEntry => $this->service->post($this->accountant, $entry))
            ->toThrow(InvalidJournalEntryLine::class, 'does not accept postings');
    });

    it('refuses a line targeting an inactive account', function (): void {
        $retired = ChartAccount::factory()->inactive()->create(['code' => '1999']);

        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), [
            ['chart_account_id' => (int) $retired->getKey(), 'debit' => '100.00', 'credit' => '0.00'],
            ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '100.00'],
        ]);

        expect(fn (): JournalEntry => $this->service->post($this->accountant, $entry))
            ->toThrow(InvalidJournalEntryLine::class, 'which is inactive');
    });

    it('refuses an entry whose date falls in no fiscal period', function (): void {
        $entry = $this->service->draft(
            $this->accountant,
            CarbonImmutable::now()->addYears(5),
            balancedLines(),
        );

        expect(fn (): JournalEntry => $this->service->post($this->accountant, $entry))
            ->toThrow(NoFiscalPeriodForDate::class);
    });

    it('refuses an entry dated inside a closed period', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), balancedLines());
        $this->period->update(['is_closed' => true]);

        expect(fn (): JournalEntry => $this->service->post($this->accountant, $entry))
            ->toThrow(ClosedFiscalPeriod::class, (string) $this->period->name);

        expect($entry->fresh()->status)->toBe(JournalEntryStatus::Draft);
    });

    it('refuses to post an already posted entry', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), balancedLines());
        $this->service->post($this->accountant, $entry);

        expect(fn (): JournalEntry => $this->service->post($this->accountant, $entry->fresh()))
            ->toThrow(AuthorizationException::class);
    });

    it('refuses a user without the post permission', function (): void {
        $reviewer = User::factory()->create();
        $reviewer->assignRole(DashboardRole::Reviewer->value);

        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), balancedLines());

        expect(fn (): JournalEntry => $this->service->post($reviewer, $entry))
            ->toThrow(AuthorizationException::class);
    });

    it('writes an audit entry attributed to the actor', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), balancedLines());

        $this->service->post($this->accountant, $entry);

        // The event name lands in `description`, matching how every other module
        // calls `activity()->log(...)` — see ServiceRecordTest's assertions.
        $this->assertDatabaseHas('activity_log', [
            'description' => 'accounting.journal_entry.posted',
            'subject_type' => JournalEntry::class,
            'subject_id' => $entry->getKey(),
            'causer_id' => $this->accountant->getKey(),
        ]);
    });

    it('leaves nothing written when validation fails', function (): void {
        $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), [
            ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '100.00', 'credit' => '0.00'],
            ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '90.00'],
        ]);

        try {
            $this->service->post($this->accountant, $entry);
        } catch (UnbalancedJournalEntry) {
            // expected
        }

        expect($entry->fresh()->fiscal_period_id)->toBeNull();
        $this->assertDatabaseMissing('activity_log', ['description' => 'accounting.journal_entry.posted']);
    });
});

describe('postNew', function (): void {
    it('drafts and posts in one call', function (): void {
        $entry = $this->service->postNew($this->accountant, CarbonImmutable::now(), balancedLines());

        expect($entry->status)->toBe(JournalEntryStatus::Posted)
            ->and($entry->fiscal_period_id)->toBe($this->period->getKey())
            ->and($entry->lines)->toHaveCount(2);
    });

    it('records the originating document on the entry', function (): void {
        $source = FiscalPeriod::factory()->forMonth(CarbonImmutable::now()->subYear())->create();

        $entry = $this->service->postNew(
            $this->accountant,
            CarbonImmutable::now(),
            balancedLines(),
            'From a document',
            $source,
        );

        expect($entry->source_type)->toBe(FiscalPeriod::class)
            ->and($entry->source_id)->toBe($source->getKey());
    });

    it('persists nothing when the posting half fails', function (): void {
        $unbalanced = [
            ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '100.00', 'credit' => '0.00'],
            ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '0.00', 'credit' => '90.00'],
        ];

        expect(fn (): JournalEntry => $this->service->postNew($this->accountant, CarbonImmutable::now(), $unbalanced))
            ->toThrow(UnbalancedJournalEntry::class);

        expect(JournalEntry::query()->count())->toBe(0);
    });
});

it('never lets a posted entry be reopened through the service', function (): void {
    $entry = $this->service->postNew($this->accountant, CarbonImmutable::now(), balancedLines());

    expect(fn (): bool => $entry->update(['status' => JournalEntryStatus::Draft]))
        ->toThrow(PostedEntryIsImmutable::class);
});

it('refuses a negative amount and says which line carries it', function (): void {
    // Storable: `debit` is a signed decimal(15,2). The rejection is the service's,
    // and it fires before the both-sides and neither-side checks so the most
    // specific complaint wins (contracts/journal-posting.md §2).
    $entry = $this->service->draft($this->accountant, CarbonImmutable::now(), [
        ['chart_account_id' => (int) $this->cash->getKey(), 'debit' => '100.00', 'credit' => '0.00'],
        ['chart_account_id' => (int) $this->sales->getKey(), 'debit' => '-100.00', 'credit' => '0.00'],
    ]);

    expect(fn (): JournalEntry => $this->service->post($this->accountant, $entry))
        ->toThrow(InvalidJournalEntryLine::class, 'Line 2 has a negative amount. Use the other side instead.');

    expect($entry->refresh()->status)->toBe(JournalEntryStatus::Draft);
});

/*
 * The two lock-and-re-read guards. Both are unreachable through the policy alone —
 * JournalEntryPolicy already refuses `post` on a posted entry and `reverse` on an
 * unposted one — so each is exercised the only way it can fire in production: the
 * row changes between the authorization check, which reads the in-memory model,
 * and the `lockForUpdate()` re-read inside the transaction (FR-031).
 */
it('refuses to post when the locked row turns out to be posted already', function (): void {
    $posted = $this->service->postNew($this->accountant, CarbonImmutable::now(), balancedLines());

    // In-memory only: the policy sees a draft and allows, the lock finds posted.
    $posted->setAttribute('status', JournalEntryStatus::Draft);

    expect(fn (): JournalEntry => $this->service->post($this->accountant, $posted))
        ->toThrow(PostedEntryIsImmutable::class, 'is posted and can no longer be changed or deleted');
});

it('refuses to reverse when the locked row turns out not to be posted', function (): void {
    $draft = $this->service->draft($this->accountant, CarbonImmutable::now(), balancedLines());

    // In-memory only: the policy sees a posted entry and allows, the lock finds a
    // draft — so there is nothing in the ledger to reverse.
    $draft->setAttribute('status', JournalEntryStatus::Posted);

    expect(fn (): JournalEntry => $this->service->reverse($this->chief, $draft))
        ->toThrow(PostedEntryIsImmutable::class, 'is not posted, so there is nothing to reverse');
});
