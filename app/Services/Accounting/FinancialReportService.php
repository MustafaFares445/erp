<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountElement;
use App\Enums\JournalEntryStatus;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\Exceptions\InvalidReportRange;
use App\Services\Accounting\Support\AccountTree;
use App\Services\Accounting\Support\LedgerAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * The single entry point for every figure this feature displays.
 *
 * Reads five existing tables and writes nothing (FR-052). Every date bound is
 * inclusive at both ends (FR-009); an inverted range throws rather than
 * returning an empty result (FR-010), because an empty report would render as
 * "no activity", a wrong answer presented as a fact.
 *
 * Every row a statement displays is a depth-first roll-up
 * ({@see AccountTree::rollUp()}) of an account and its descendants, so a
 * header account's row is never zero merely because it holds no line of its
 * own. Every section or grand total, by contrast, sums each account's *own*
 * value only — never a rolled one — which is what keeps a descendant from
 * being counted twice: a header account never receives a direct posting, so
 * its own value is always zero and it contributes nothing extra to a total
 * its descendants already contributed to (FR-014, invariant I-10).
 *
 * @see /specs/020-accounting-financial-reports/contracts/financial-report-service.md
 */
final readonly class FinancialReportService
{
    /**
     * @return array{
     *     rows: list<array{accountId: int, code: string, name: string, element: string, depth: int, isDeleted: bool, openingBalance: string, periodDebit: string, periodCredit: string, closingBalance: string}>,
     *     totalDebit: string,
     *     totalCredit: string,
     *     foots: bool,
     *     variance: string,
     * }
     */
    public function trialBalance(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->assertValidRange($from, $to);

        $tree = new AccountTree;
        $opening = LedgerAggregate::before($from);
        $movement = LedgerAggregate::inRange($from, $to);

        $rows = [];

        foreach ($tree->displayOrder() as $account) {
            $openingRolled = $tree->rollUp($account->id, fn (int $id): int => $tree->signOf($id) * $opening->netMinorFor($id));
            $debitRolled = $tree->rollUp($account->id, fn (int $id): int => $movement->debitMinorFor($id));
            $creditRolled = $tree->rollUp($account->id, fn (int $id): int => $movement->creditMinorFor($id));

            if ($openingRolled === 0 && $debitRolled === 0 && $creditRolled === 0) {
                continue;
            }

            $closingRolled = $tree->rollUp(
                $account->id,
                fn (int $id): int => $tree->signOf($id) * ($opening->netMinorFor($id) + $movement->netMinorFor($id)),
            );

            $rows[] = [
                'accountId' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'element' => $this->elementLabel($account),
                'depth' => $tree->depthOf($account->id),
                'isDeleted' => $account->trashed(),
                'openingBalance' => self::formatMinor($openingRolled),
                'periodDebit' => self::formatMinor($debitRolled),
                'periodCredit' => self::formatMinor($creditRolled),
                'closingBalance' => self::formatMinor($closingRolled),
            ];
        }

        $totalDebitMinor = array_sum(array_map($movement->debitMinorFor(...), $movement->accountIds()));
        $totalCreditMinor = array_sum(array_map($movement->creditMinorFor(...), $movement->accountIds()));

        return [
            'rows' => $rows,
            'totalDebit' => self::formatMinor($totalDebitMinor),
            'totalCredit' => self::formatMinor($totalCreditMinor),
            'foots' => $totalDebitMinor === $totalCreditMinor,
            'variance' => self::formatMinor($totalDebitMinor - $totalCreditMinor),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array{lineId: int, entryId: int, entryNumber: string, entryDate: string, accountCode: string, accountName: string, description: ?string, debit: string, credit: string, runningBalance: string}>
     */
    public function generalLedger(CarbonImmutable $from, CarbonImmutable $to, ?int $accountId, int $perPage): LengthAwarePaginator
    {
        $this->assertValidRange($from, $to);

        $tree = new AccountTree;
        $scopeIds = $accountId !== null ? $tree->selfAndDescendantIds($accountId) : null;

        $query = JournalEntryLine::query()
            ->whereHas('journalEntry', function (Builder $query) use ($from, $to): void {
                // whereDate(), not whereBetween(): see LedgerAggregate for why
                // an exact-day upper bound needs the date part extracted
                // rather than compared as a raw string.
                $query->where('status', JournalEntryStatus::Posted->value)
                    ->whereDate('entry_date', '>=', $from->toDateString())
                    ->whereDate('entry_date', '<=', $to->toDateString());
            })
            ->with(['journalEntry', 'chartAccount'])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_entry_lines.sort_order')
            ->select('journal_entry_lines.*');

        if ($scopeIds !== null) {
            $query->whereIn('journal_entry_lines.chart_account_id', $scopeIds);
        }

        $lines = $query->get();
        $opening = LedgerAggregate::before($from);

        $running = [];

        if ($accountId !== null) {
            $running[$accountId] = $tree->rollUp($accountId, fn (int $id): int => $opening->netMinorFor($id));
        }

        $rows = [];

        foreach ($lines as $line) {
            /** @var JournalEntry $entry */
            $entry = $line->journalEntry;
            $lineAccountId = (int) $line->chart_account_id;
            $trackingId = $accountId ?? $lineAccountId;

            $running[$trackingId] ??= $opening->netMinorFor($trackingId);
            $running[$trackingId] += $line->signedMinorUnits();

            $sign = $tree->signOf($accountId ?? $lineAccountId);
            $account = $line->chartAccount;

            $rows[] = [
                'lineId' => $line->id,
                'entryId' => $entry->id,
                'entryNumber' => $entry->entry_number,
                'entryDate' => $entry->entry_date->toDateString(),
                'accountCode' => $account === null ? '' : $account->code,
                'accountName' => $account === null ? '' : $account->name,
                'description' => $line->description,
                'debit' => self::formatMinor(JournalEntryLine::toMinorUnits($line->debit)),
                'credit' => self::formatMinor(JournalEntryLine::toMinorUnits($line->credit)),
                'runningBalance' => self::formatMinor($sign * $running[$trackingId]),
            ];
        }

        return $this->paginate($rows, $perPage);
    }

    /**
     * @return array{
     *     sections: array<string, array{rows: list<array{accountId: int, code: string, name: string, depth: int, amount: string}>, subtotal: string}>,
     *     netResult: string,
     *     isLoss: bool,
     * }
     */
    public function profitAndLoss(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->assertValidRange($from, $to);

        $tree = new AccountTree;
        $movement = LedgerAggregate::inRange($from, $to);

        $income = $this->section($tree, $movement, AccountElement::Income);
        $expense = $this->section($tree, $movement, AccountElement::Expense);

        $netResultMinor = self::toMinor($income['subtotal']) - self::toMinor($expense['subtotal']);

        return [
            'sections' => [
                'income' => $income,
                'expense' => $expense,
            ],
            'netResult' => self::formatMinor($netResultMinor),
            'isLoss' => $netResultMinor < 0,
        ];
    }

    /**
     * @return array{
     *     sections: array<string, array{rows: list<array{accountId: int, code: string, name: string, depth: int, amount: string}>, subtotal: string}>,
     *     accumulatedEarnings: string,
     *     totalAssets: string,
     *     totalLiabilities: string,
     *     totalPostedEquity: string,
     *     balances: bool,
     *     variance: string,
     * }
     */
    public function balanceSheet(CarbonImmutable $asOf): array
    {
        $tree = new AccountTree;
        $aggregate = LedgerAggregate::onOrBefore($asOf);

        $asset = $this->section($tree, $aggregate, AccountElement::Asset);
        $liability = $this->section($tree, $aggregate, AccountElement::Liability);
        $equity = $this->section($tree, $aggregate, AccountElement::Equity);

        $incomeMinor = $this->elementOwnTotal($tree, $aggregate, AccountElement::Income);
        $expenseMinor = $this->elementOwnTotal($tree, $aggregate, AccountElement::Expense);
        $accumulatedEarningsMinor = $incomeMinor - $expenseMinor;

        $totalAssetsMinor = self::toMinor($asset['subtotal']);
        $totalLiabilitiesMinor = self::toMinor($liability['subtotal']);
        $totalPostedEquityMinor = self::toMinor($equity['subtotal']);

        $varianceMinor = $totalAssetsMinor - ($totalLiabilitiesMinor + $totalPostedEquityMinor + $accumulatedEarningsMinor);

        return [
            'sections' => [
                'asset' => $asset,
                'liability' => $liability,
                'equity' => $equity,
            ],
            'accumulatedEarnings' => self::formatMinor($accumulatedEarningsMinor),
            'totalAssets' => self::formatMinor($totalAssetsMinor),
            'totalLiabilities' => self::formatMinor($totalLiabilitiesMinor),
            'totalPostedEquity' => self::formatMinor($totalPostedEquityMinor),
            'balances' => $varianceMinor === 0,
            'variance' => self::formatMinor($varianceMinor),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array{entryId: int, entryNumber: string, entryDate: string, description: ?string, fiscalPeriodName: ?string, postedByName: ?string, source: ?array{label: string, type: string, id: int, resolved: bool}, lines: list<array{accountCode: string, accountName: string, debit: string, credit: string}>}>
     */
    public function postingRegister(CarbonImmutable $from, CarbonImmutable $to, int $perPage): LengthAwarePaginator
    {
        $this->assertValidRange($from, $to);

        $entries = JournalEntry::query()
            ->where('status', JournalEntryStatus::Posted->value)
            ->whereDate('entry_date', '>=', $from->toDateString())
            ->whereDate('entry_date', '<=', $to->toDateString())
            ->with(['lines.chartAccount', 'fiscalPeriod', 'updatedBy', 'source'])
            ->orderBy('entry_date')
            ->orderBy('entry_number')
            ->get();

        $rows = $entries->map(fn (JournalEntry $entry): array => [
            'entryId' => $entry->id,
            'entryNumber' => $entry->entry_number,
            'entryDate' => $entry->entry_date->toDateString(),
            'description' => $entry->description,
            'fiscalPeriodName' => $entry->fiscalPeriod?->name,
            'postedByName' => $entry->updatedBy?->name,
            'source' => $this->resolveSource($entry),
            'lines' => array_values($entry->lines->map($this->postingRegisterLine(...))->all()),
        ])->all();

        $rows = array_values($rows);

        return $this->paginate($rows, $perPage);
    }

    /** @return array<int, string> */
    public function fiscalPeriodOptions(): array
    {
        return FiscalPeriod::query()
            ->orderBy('starts_at')
            ->get()
            ->mapWithKeys(fn (FiscalPeriod $period): array => [$period->id => $period->name])
            ->all();
    }

    private function assertValidRange(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($to->lessThan($from)) {
            throw InvalidReportRange::endBeforeStart($from->toDateString(), $to->toDateString());
        }
    }

    /**
     * One section (all accounts of one element) for the Profit and Loss or
     * Balance Sheet: each row is a depth-first roll-up, the subtotal is the
     * sum of each account's own value only (FR-014).
     *
     * @return array{rows: list<array{accountId: int, code: string, name: string, depth: int, amount: string}>, subtotal: string}
     */
    private function section(AccountTree $tree, LedgerAggregate $aggregate, AccountElement $element): array
    {
        $signOf = fn (int $id): int => $tree->signOf($id);
        $rows = [];

        foreach ($tree->displayOrder() as $account) {
            if ($account->accountType?->name !== $element) {
                continue;
            }

            $amountRolled = $tree->rollUp($account->id, fn (int $id): int => $signOf($id) * $aggregate->netMinorFor($id));

            if ($amountRolled === 0) {
                continue;
            }

            $rows[] = [
                'accountId' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'depth' => $tree->depthOf($account->id),
                'amount' => self::formatMinor($amountRolled),
            ];
        }

        return [
            'rows' => $rows,
            'subtotal' => self::formatMinor($this->elementOwnTotal($tree, $aggregate, $element)),
        ];
    }

    /**
     * The sum of every account's *own* (unrolled) value for one element —
     * the correct subtotal, because a header account's own value is always
     * zero and every leaf is counted exactly once (FR-014, invariant I-10).
     */
    private function elementOwnTotal(AccountTree $tree, LedgerAggregate $aggregate, AccountElement $element): int
    {
        $total = 0;

        foreach ($aggregate->accountIds() as $accountId) {
            $account = $tree->accountById($accountId);

            if ($account?->accountType?->name !== $element) {
                continue;
            }

            $total += $tree->signOf($accountId) * $aggregate->netMinorFor($accountId);
        }

        return $total;
    }

    private function elementLabel(ChartAccount $account): string
    {
        return $account->accountType?->name->label() ?? '';
    }

    /** @return array{accountCode: string, accountName: string, debit: string, credit: string} */
    private function postingRegisterLine(JournalEntryLine $line): array
    {
        $account = $line->chartAccount;

        return [
            'accountCode' => $account === null ? '' : $account->code,
            'accountName' => $account === null ? '' : $account->name,
            'debit' => self::formatMinor(JournalEntryLine::toMinorUnits($line->debit)),
            'credit' => self::formatMinor(JournalEntryLine::toMinorUnits($line->credit)),
        ];
    }

    /**
     * @return array{label: string, type: string, id: int, resolved: bool}|null
     */
    private function resolveSource(JournalEntry $entry): ?array
    {
        $sourceType = $entry->source_type;
        $sourceId = $entry->source_id;

        if ($sourceType === null || $sourceId === null) {
            return null;
        }

        $target = $entry->source;

        if ($target instanceof JournalEntry) {
            return [
                'label' => (string) $target->entry_number,
                'type' => 'JournalEntry',
                'id' => (int) $sourceId,
                'resolved' => true,
            ];
        }

        $shortType = class_basename($sourceType);

        if ($target === null) {
            return [
                'label' => sprintf('%s #%d (unresolved)', $shortType, $sourceId),
                'type' => $shortType,
                'id' => (int) $sourceId,
                'resolved' => false,
            ];
        }

        return [
            'label' => sprintf('%s #%d', $shortType, $sourceId),
            'type' => $shortType,
            'id' => (int) $sourceId,
            'resolved' => true,
        ];
    }

    /**
     * @template TValue
     *
     * @param  list<TValue>  $rows
     * @return LengthAwarePaginator<int, TValue>
     */
    private function paginate(array $rows, int $perPage): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new LengthAwarePaginator($items, count($rows), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    private static function toMinor(string $decimal): int
    {
        return JournalEntryLine::toMinorUnits($decimal);
    }

    private static function formatMinor(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
