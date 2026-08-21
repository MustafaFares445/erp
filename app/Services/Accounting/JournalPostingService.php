<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\JournalEntryStatus;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Accounting\Exceptions\ClosedFiscalPeriod;
use App\Services\Accounting\Exceptions\EntryAlreadyReversed;
use App\Services\Accounting\Exceptions\InvalidJournalEntryLine;
use App\Services\Accounting\Exceptions\NoFiscalPeriodForDate;
use App\Services\Accounting\Exceptions\PostedEntryIsImmutable;
use App\Services\Accounting\Exceptions\UnbalancedJournalEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The single write path into the general ledger.
 *
 * This is the interface `Docs/IMPLEMENTATION_PLAN.md` §6 calls the "posting
 * service interface for invoices, payments, tax, credit notes". This feature
 * builds it and its manual caller only: **no document is wired to it** (FR-034),
 * because ADR 0007 authorises no automatic posting. Connecting a document is that
 * document's own feature and ADR.
 *
 * Every public method takes an explicit `User $actor` and authorizes exactly one
 * ability against it, so a direct service call is never an authorization bypass
 * and no operation silently demands a second permission. The shared internals
 * ({@see self::createEntry()}, {@see self::commit()}) perform no authorization,
 * which is why they are private: a reversal must need only
 * `accounting.journal-entry.reverse`, not also `create` and `post`.
 *
 * Nothing here calls `auth()`, and an architecture test proves it.
 *
 * @see /specs/018-chart-of-accounts-journals/contracts/journal-posting.md
 */
final readonly class JournalPostingService
{
    public function __construct(private FiscalPeriodService $fiscalPeriods) {}

    /**
     * Creates an unposted entry. Nothing is validated beyond foreign keys — a
     * draft is allowed to be unbalanced and incomplete, which is what makes it a
     * draft (research.md R-012).
     *
     * @param  list<array{chart_account_id: int|string, debit?: float|int|string|null, credit?: float|int|string|null, description?: string|null}>  $lines
     */
    public function draft(
        User $actor,
        CarbonInterface $entryDate,
        array $lines,
        ?string $description = null,
        ?Model $source = null,
    ): JournalEntry {
        Gate::forUser($actor)->authorize('create', JournalEntry::class);

        return $this->createEntry($actor, $entryDate, $lines, $description, $source);
    }

    /**
     * Commits a draft to the ledger.
     */
    public function post(User $actor, JournalEntry $entry): JournalEntry
    {
        Gate::forUser($actor)->authorize('post', $entry);

        return $this->commit($actor, $entry);
    }

    /**
     * Draft and post in one transaction, for callers with nothing to review.
     *
     * Deliberately requires both `create` and `post`, because it genuinely
     * performs both operations.
     *
     * @param  list<array{chart_account_id: int|string, debit?: float|int|string|null, credit?: float|int|string|null, description?: string|null}>  $lines
     */
    public function postNew(
        User $actor,
        CarbonInterface $entryDate,
        array $lines,
        ?string $description = null,
        ?Model $source = null,
    ): JournalEntry {
        return DB::transaction(
            fn (): JournalEntry => $this->post(
                $actor,
                $this->draft($actor, $entryDate, $lines, $description, $source),
            ),
        );
    }

    /**
     * Corrects a posted entry by posting its mirror image.
     *
     * The reversal is an ordinary posting, validated against its **own** resolved
     * period rather than the original's — so a correction can land in a later open
     * period, but never back inside a closed one (FR-029).
     */
    public function reverse(
        User $actor,
        JournalEntry $entry,
        ?CarbonInterface $reversalDate = null,
        ?string $description = null,
    ): JournalEntry {
        Gate::forUser($actor)->authorize('reverse', $entry);

        return DB::transaction(function () use ($actor, $entry, $reversalDate, $description): JournalEntry {
            $locked = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->getRawOriginal('status') !== JournalEntryStatus::Posted->value) {
                throw PostedEntryIsImmutable::notPosted((string) $locked->entry_number);
            }

            $existing = $locked->reversal;

            if ($existing instanceof JournalEntry) {
                throw EntryAlreadyReversed::by((string) $existing->entry_number);
            }

            $mirrored = array_values($locked->lines()->get()
                ->map(static fn (JournalEntryLine $line): array => [
                    'chart_account_id' => $line->chart_account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => $line->description,
                ])
                ->all());

            $reversal = $this->commit($actor, $this->createEntry(
                $actor,
                $reversalDate ?? $locked->entry_date,
                $mirrored,
                $description ?? __('admin.accounting.reversal_description', ['entry' => $locked->entry_number]),
                $locked,
            ));

            // Logged against the original as well as the reversal: reconstructing
            // the ledger's history from the audit trail alone needs both halves.
            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['reversed_by' => $reversal->entry_number]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('accounting.journal_entry.reversed');

            return $reversal;
        });
    }

    /**
     * @param  list<array{chart_account_id: int|string, debit?: float|int|string|null, credit?: float|int|string|null, description?: string|null}>  $lines
     */
    private function createEntry(
        User $actor,
        CarbonInterface $entryDate,
        array $lines,
        ?string $description,
        ?Model $source,
    ): JournalEntry {
        return DB::transaction(function () use ($actor, $entryDate, $lines, $description, $source): JournalEntry {
            $entry = new JournalEntry([
                'entry_date' => $entryDate->toDateString(),
                'description' => $description,
                'status' => JournalEntryStatus::Draft->value,
                // Allocated here rather than left to the model's `creating` hook,
                // mirroring `InventoryOperationService::nextOperationNumber()`
                // (research.md R-009). The hook remains as the backstop for direct
                // and factory writes, but a seeder running under
                // `WithoutModelEvents` mutes it — and the write path must not
                // depend on an event another caller can switch off.
                'entry_number' => JournalEntry::nextEntryNumber(),
            ]);

            if ($source instanceof Model) {
                $entry->source()->associate($source);
            }

            $entry->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            foreach ($lines as $index => $line) {
                $entry->lines()->create([
                    'chart_account_id' => (int) $line['chart_account_id'],
                    'debit' => self::normalizeAmount($line['debit'] ?? null),
                    'credit' => self::normalizeAmount($line['credit'] ?? null),
                    'description' => $line['description'] ?? null,
                    'sort_order' => $index + 1,
                ]);
            }

            return $entry->refresh();
        });
    }

    /**
     * Validates and commits, with no authorization of its own.
     *
     * Validation order is fixed by contracts/journal-posting.md §2 so the most
     * useful message wins, and every check runs before any write, so a rejection
     * rolls back a transaction that changed nothing.
     */
    private function commit(User $actor, JournalEntry $entry): JournalEntry
    {
        return DB::transaction(function () use ($actor, $entry): JournalEntry {
            // Re-read under a row lock: two concurrent posts serialise here and
            // the loser finds the entry already committed (FR-031).
            $locked = JournalEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->getRawOriginal('status') === JournalEntryStatus::Posted->value) {
                throw PostedEntryIsImmutable::forEntry((string) $locked->entry_number);
            }

            $lines = $locked->lines()->with('chartAccount')->get();

            if ($lines->count() < 2) {
                throw UnbalancedJournalEntry::tooFewLines($lines->count());
            }

            $debitMinor = 0;
            $creditMinor = 0;

            foreach ($lines->values() as $index => $line) {
                $position = $index + 1;
                $debit = JournalEntryLine::toMinorUnits($line->debit);
                $credit = JournalEntryLine::toMinorUnits($line->credit);

                if ($debit < 0 || $credit < 0) {
                    throw InvalidJournalEntryLine::negativeAmount($position);
                }

                if ($debit !== 0 && $credit !== 0) {
                    throw InvalidJournalEntryLine::bothSidesSet($position);
                }

                if ($debit === 0 && $credit === 0) {
                    throw InvalidJournalEntryLine::neitherSideSet($position);
                }

                $account = $line->chartAccount;

                if (! $account instanceof ChartAccount || ! $account->is_postable) {
                    throw InvalidJournalEntryLine::accountNotPostable(
                        $position,
                        (string) ($account->code ?? '?'),
                    );
                }

                if (! $account->is_active) {
                    throw InvalidJournalEntryLine::accountInactive($position, (string) $account->code);
                }

                $debitMinor += $debit;
                $creditMinor += $credit;
            }

            if ($debitMinor !== $creditMinor) {
                throw UnbalancedJournalEntry::totals(
                    self::formatMinorUnits($debitMinor),
                    self::formatMinorUnits($creditMinor),
                );
            }

            $period = $this->fiscalPeriods->forDate($locked->entry_date);

            if (! $period instanceof FiscalPeriod) {
                throw NoFiscalPeriodForDate::forDate($locked->entry_date->toDateString());
            }

            if ($period->is_closed) {
                throw ClosedFiscalPeriod::named((string) $period->name);
            }

            $locked->update([
                'status' => JournalEntryStatus::Posted->value,
                'fiscal_period_id' => $period->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => JournalEntryStatus::Draft->value],
                    'attributes' => [
                        'status' => JournalEntryStatus::Posted->value,
                        'fiscal_period_id' => $period->getKey(),
                    ],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('accounting.journal_entry.posted');

            return $locked->refresh();
        });
    }

    private static function normalizeAmount(float|int|string|null $amount): string
    {
        return self::formatMinorUnits(JournalEntryLine::toMinorUnits($amount ?? 0));
    }

    private static function formatMinorUnits(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
