<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\JournalEntryStatus;
use App\Models\ChartAccount;
use App\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Account balances and per-account ledger lines, computed from the posted lines
 * themselves rather than from a stored column.
 *
 * A stored balance is a cache that must be invalidated on every posting and
 * reversal, and it is the first thing to silently disagree with reality after any
 * direct write (research.md R-008). The ledger's whole value is that the lines
 * *are* the truth, so nothing here writes.
 *
 * All arithmetic is in integer minor units and only converted back to a decimal
 * string at the boundary, so no float ever participates (FR-030).
 *
 * @see /specs/018-chart-of-accounts-journals/contracts/journal-posting.md §6
 */
final readonly class AccountBalanceService
{
    /**
     * An account's balance as a 2-decimal string, signed so an account holding
     * its normal balance reads positive (FR-036).
     */
    public function balanceFor(ChartAccount $account, bool $includeDescendants = true): string
    {
        $accountIds = $includeDescendants
            ? $account->selfAndDescendantIds()
            : [$account->id];

        $sign = $this->signFor($account);

        return self::format($sign * $this->netMinorUnitsFor($accountIds));
    }

    /**
     * Every account's balance, keyed by account id.
     *
     * One aggregate query plus one in-memory tree walk, rather than a query per
     * row: the Chart of Accounts table needs all of them at once, and this is the
     * only place in the feature where N+1 would be easy to introduce. It is a read
     * path, so it cannot affect the ledger's correctness either way.
     *
     * @return array<int, string>
     */
    public function balancesForAll(bool $includeDescendants = true): array
    {
        /** @var Collection<int, ChartAccount> $accounts */
        $accounts = ChartAccount::query()->with('accountType')->get();

        /** @var array<int, int> $ownNetMinor */
        $ownNetMinor = $this->netMinorUnitsByAccount();

        /** @var array<int, list<int>> $childrenOf */
        $childrenOf = [];

        foreach ($accounts as $account) {
            $parentId = $account->parent_id;

            if ($parentId !== null) {
                $childrenOf[$parentId][] = $account->id;
            }
        }

        $balances = [];

        foreach ($accounts as $account) {
            $accountId = $account->id;

            $netMinor = $includeDescendants
                ? $this->rollUp($accountId, $childrenOf, $ownNetMinor)
                : ($ownNetMinor[$accountId] ?? 0);

            $balances[$accountId] = self::format($this->signFor($account) * $netMinor);
        }

        return $balances;
    }

    /**
     * An account's posted lines, oldest first, for the ledger read surface
     * (FR-038).
     *
     * Own lines only — a parent's ledger would otherwise interleave lines that
     * were never posted to it.
     *
     * @return Collection<int, JournalEntryLine>
     */
    public function ledgerFor(ChartAccount $account): Collection
    {
        return JournalEntryLine::query()
            ->where('chart_account_id', $account->getKey())
            ->whereHas('journalEntry', fn (Builder $query): Builder => $query->where('status', JournalEntryStatus::Posted->value))
            ->with('journalEntry')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_entry_lines.sort_order')
            ->select('journal_entry_lines.*')
            ->get();
    }

    /**
     * A running balance per line, in the same order {@see self::ledgerFor()}
     * returns, signed the same way as {@see self::balanceFor()}.
     *
     * @param  Collection<int, JournalEntryLine>  $lines
     * @return array<int, string> keyed by line id
     */
    public function runningBalances(ChartAccount $account, Collection $lines): array
    {
        $sign = $this->signFor($account);
        $running = 0;
        $balances = [];

        foreach ($lines as $line) {
            $running += $line->signedMinorUnits();
            $balances[$line->id] = self::format($sign * $running);
        }

        return $balances;
    }

    /**
     * Net debit-minus-credit in minor units across the given accounts, posted
     * lines only (FR-035).
     *
     * @param  list<int>  $accountIds
     */
    private function netMinorUnitsFor(array $accountIds): int
    {
        if ($accountIds === []) {
            return 0;
        }

        /** @var object{debit_total: mixed, credit_total: mixed}|null $totals */
        $totals = JournalEntryLine::query()
            ->whereIn('chart_account_id', $accountIds)
            ->whereHas('journalEntry', fn (Builder $query): Builder => $query->where('status', JournalEntryStatus::Posted->value))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')
            ->first();

        // @codeCoverageIgnoreStart
        // Unreachable in practice: an aggregate SELECT with COALESCE always returns
        // exactly one row, even over zero matching lines. The guard exists only to
        // narrow first()'s nullable return for static analysis.
        if ($totals === null) {
            return 0;
        }

        // @codeCoverageIgnoreEnd

        return JournalEntryLine::toMinorUnits($totals->debit_total)
            - JournalEntryLine::toMinorUnits($totals->credit_total);
    }

    /**
     * Each account's own net minor units, in one grouped query.
     *
     * @return array<int, int>
     */
    private function netMinorUnitsByAccount(): array
    {
        $rows = JournalEntryLine::query()
            ->whereHas('journalEntry', fn (Builder $query): Builder => $query->where('status', JournalEntryStatus::Posted->value))
            ->groupBy('chart_account_id')
            ->select('chart_account_id')
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total')
            ->selectRaw('COALESCE(SUM(credit), 0) as credit_total')
            ->get();

        $byAccount = [];

        foreach ($rows as $row) {
            $byAccount[(int) $row->chart_account_id] =
                JournalEntryLine::toMinorUnits($row->getAttribute('debit_total'))
                - JournalEntryLine::toMinorUnits($row->getAttribute('credit_total'));
        }

        return $byAccount;
    }

    /**
     * Sums an account's own net with its whole subtree's (FR-037).
     *
     * `$visited` is carried so a cycle introduced by a direct database write
     * cannot make this recurse forever — the same reason
     * {@see ChartAccount::selfAndDescendantIds()} tracks what it has seen.
     *
     * @param  array<int, list<int>>  $childrenOf
     * @param  array<int, int>  $ownNetMinor
     * @param  array<int, true>  $visited
     */
    private function rollUp(int $accountId, array $childrenOf, array $ownNetMinor, array $visited = []): int
    {
        if (isset($visited[$accountId])) {
            return 0;
        }

        $visited[$accountId] = true;
        $total = $ownNetMinor[$accountId] ?? 0;

        foreach ($childrenOf[$accountId] ?? [] as $childId) {
            $total += $this->rollUp($childId, $childrenOf, $ownNetMinor, $visited);
        }

        return $total;
    }

    /**
     * Falls back to debit-normal only when the account type is somehow missing,
     * which the non-nullable `account_type_id` foreign key already prevents.
     */
    private function signFor(ChartAccount $account): int
    {
        $accountType = $account->accountType;

        return $accountType === null ? 1 : $accountType->normal_balance->sign();
    }

    private static function format(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
