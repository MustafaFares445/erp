<?php

declare(strict_types=1);

namespace App\Services\Accounting\Support;

use App\Enums\JournalEntryStatus;
use App\Enums\NormalBalance;
use App\Models\JournalEntryLine;
use App\Services\Accounting\AccountBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-account debit and credit totals, in integer minor units, for one
 * date-bounded window over posted lines.
 *
 * Built from exactly one grouped aggregate query — the same shape
 * {@see AccountBalanceService::netMinorUnitsByAccount()}
 * already establishes, with a date predicate added (research §R1). `COALESCE`
 * in the query means a missing account yields `0` rather than `null`, so no
 * caller needs a null check.
 *
 * @see /specs/020-accounting-financial-reports/data-model.md §LedgerAggregate
 */
final readonly class LedgerAggregate
{
    /** @param array<int, array{debit: int, credit: int}> $totals */
    private function __construct(private array $totals) {}

    /**
     * Posted lines whose entry date falls strictly before `$date` — the
     * opening-balance bound for a Trial Balance or General Ledger.
     */
    public static function before(CarbonImmutable $date): self
    {
        return self::build(function (Builder $query) use ($date): void {
            // whereDate(), not where(): SQLite serialises a `date`-cast column
            // with a `00:00:00` time suffix, which would make an exact-day
            // upper bound compare as *greater than* the plain date string and
            // silently exclude that day. whereDate() extracts the date part on
            // every driver, so the comparison is exact regardless of storage.
            $query->whereDate('entry_date', '<', $date->toDateString());
        });
    }

    /**
     * Posted lines whose entry date falls between `$from` and `$to`,
     * inclusive at both ends (FR-009) — the movement bound for a Trial
     * Balance's period columns and for the Profit and Loss statement.
     */
    public static function inRange(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return self::build(function (Builder $query) use ($from, $to): void {
            $query->whereDate('entry_date', '>=', $from->toDateString())
                ->whereDate('entry_date', '<=', $to->toDateString());
        });
    }

    /**
     * Posted lines whose entry date falls on or before `$asOf`, inclusive —
     * the Balance Sheet's bound.
     */
    public static function onOrBefore(CarbonImmutable $asOf): self
    {
        return self::build(function (Builder $query) use ($asOf): void {
            $query->whereDate('entry_date', '<=', $asOf->toDateString());
        });
    }

    public function debitMinorFor(int $accountId): int
    {
        return $this->totals[$accountId]['debit'] ?? 0;
    }

    public function creditMinorFor(int $accountId): int
    {
        return $this->totals[$accountId]['credit'] ?? 0;
    }

    /**
     * Debit minus credit, unsigned by normal balance — the caller applies
     * {@see NormalBalance::sign()} where a signed figure is needed.
     */
    public function netMinorFor(int $accountId): int
    {
        return $this->debitMinorFor($accountId) - $this->creditMinorFor($accountId);
    }

    /** @return list<int> */
    public function accountIds(): array
    {
        return array_keys($this->totals);
    }

    private static function build(callable $dateBound): self
    {
        $rows = JournalEntryLine::query()
            ->whereHas('journalEntry', function (Builder $query) use ($dateBound): void {
                $query->where('status', JournalEntryStatus::Posted->value);
                $dateBound($query);
            })
            ->groupBy('chart_account_id')
            ->select('chart_account_id')
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total')
            ->selectRaw('COALESCE(SUM(credit), 0) as credit_total')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->chart_account_id] = [
                'debit' => JournalEntryLine::toMinorUnits($row->getAttribute('debit_total')),
                'credit' => JournalEntryLine::toMinorUnits($row->getAttribute('credit_total')),
            ];
        }

        return new self($totals);
    }
}
