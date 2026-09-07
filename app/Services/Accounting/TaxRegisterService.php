<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\CreditNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JournalEntryStatus;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\SalesSetting;
use App\Models\TaxRecognitionEntry;
use App\Services\Sales\SalesAccountResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use LogicException;

/**
 * Derives the tax register for a period: the deferred-versus-payable split
 * that is this company's tax policy (WP-2.7). Entries in
 * {@see TaxRecognitionEntry} are immutable facts; the register, like
 * {@see AccountsReceivableService}'s subledger, stores no balance of its own
 * and recomputes every figure from source documents on each call.
 *
 * @phpstan-type PeriodFigures array{
 *     output_tax_charged_deferred: string,
 *     output_tax_recognised_payable: string,
 *     output_tax_reversed: string,
 *     input_tax_recognised: string,
 *     net_position: string,
 * }
 * @phpstan-type AccountReconciliation array{register: string, journal: string, difference: string}
 * @phpstan-type Reconciliation array{deferred: AccountReconciliation, payable: AccountReconciliation, input: AccountReconciliation}
 * @phpstan-type PeriodMinor array{deferred: int, payable: int, reversals: int, credit_note_tax: int, reversed: int, input: int}
 */
final readonly class TaxRegisterService
{
    public function __construct(private SalesAccountResolver $accounts) {}

    /** @return PeriodFigures */
    public function period(CarbonInterface $from, CarbonInterface $to): array
    {
        $minor = $this->periodMinor($from, $to);

        return [
            'output_tax_charged_deferred' => self::money($minor['deferred']),
            'output_tax_recognised_payable' => self::money($minor['payable']),
            'output_tax_reversed' => self::money($minor['reversed']),
            'input_tax_recognised' => self::money($minor['input']),
            'net_position' => self::money($minor['payable'] - $minor['reversed'] - $minor['input']),
        ];
    }

    /**
     * Compares the register's independently-derived movement for each tax
     * account against the actual ledger movement on that account. A nonzero
     * difference means a posting bypassed the canonical document flows this
     * register derives from — the entire point of the feature.
     *
     * @return Reconciliation
     */
    public function reconciliation(CarbonInterface $from, CarbonInterface $to): array
    {
        $minor = $this->periodMinor($from, $to);

        $settings = SalesSetting::current()->load(['deferredTaxAccount', 'taxPayableAccount']);
        $deferredAccountId = self::keyOf($this->accounts->deferredTax($settings));
        $payableAccountId = self::keyOf($this->accounts->taxPayable($settings));
        $inputAccountId = $this->recoverableInputTaxAccountId();

        $creditNoteSplit = $this->confirmedCreditNoteJournalSplit($from, $to, $deferredAccountId, $payableAccountId);

        $deferredRegisterMinor = $minor['deferred']
            - $minor['payable']
            - $creditNoteSplit['deferred']
            + $minor['reversals'];

        $payableRegisterMinor = $minor['payable']
            - $creditNoteSplit['payable']
            - $minor['reversals'];

        $deferredJournalMinor = $this->liabilityMovementMinor($deferredAccountId, $from, $to);
        $payableJournalMinor = $this->liabilityMovementMinor($payableAccountId, $from, $to);
        $inputJournalMinor = $inputAccountId !== null
            ? $this->assetMovementMinor($inputAccountId, $from, $to)
            : 0;

        return [
            'deferred' => self::accountReconciliation($deferredRegisterMinor, $deferredJournalMinor),
            'payable' => self::accountReconciliation($payableRegisterMinor, $payableJournalMinor),
            'input' => self::accountReconciliation($minor['input'], $inputJournalMinor),
        ];
    }

    public function toCsv(CarbonInterface $from, CarbonInterface $to): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new LogicException('The tax register export stream could not be opened.');
        }

        fputcsv($stream, [
            'tax_date', 'direction', 'tax_type', 'tax_amount', 'source_type', 'source_id',
            'invoice_id', 'payment_id', 'refund_id', 'recognition_date',
        ], escape: '\\');

        foreach ($this->entriesFor($from, $to) as $entry) {
            fputcsv($stream, [
                $entry->tax_date->toDateString(),
                $entry->direction,
                $entry->tax_type,
                (string) $entry->tax_amount,
                $entry->source_type,
                $entry->source_id,
                $entry->invoice_id,
                $entry->payment_id,
                $entry->refund_id,
                $entry->recognition_date?->toDateString(),
            ], escape: '\\');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return is_string($csv) ? $csv : '';
    }

    /** @return LazyCollection<int, TaxRecognitionEntry> */
    public function entriesFor(CarbonInterface $from, CarbonInterface $to, ?string $direction = null): LazyCollection
    {
        return TaxRecognitionEntry::query()
            ->whereDate('tax_date', '>=', $from->toDateString())
            ->whereDate('tax_date', '<=', $to->toDateString())
            ->when($direction !== null, fn (Builder $query): Builder => $query->where('direction', $direction))
            ->orderBy('tax_date')
            ->orderBy('id')
            ->cursor();
    }

    /** @return PeriodMinor */
    private function periodMinor(CarbonInterface $from, CarbonInterface $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $deferredMinor = JournalEntryLine::toMinorUnits(
            Invoice::query()
                ->whereDate('invoice_date', '>=', $fromDate)
                ->whereDate('invoice_date', '<=', $toDate)
                ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
                ->sum('tax_total')
        );

        $payableMinor = JournalEntryLine::toMinorUnits(
            TaxRecognitionEntry::query()
                ->where('direction', 'output')
                ->whereDate('tax_date', '>=', $fromDate)
                ->whereDate('tax_date', '<=', $toDate)
                ->sum('recognised_tax_amount')
        );

        $reversalsMinor = abs(JournalEntryLine::toMinorUnits(
            TaxRecognitionEntry::query()
                ->whereIn('direction', ['output_reversal', 'refund'])
                ->whereDate('tax_date', '>=', $fromDate)
                ->whereDate('tax_date', '<=', $toDate)
                ->sum('tax_amount')
        ));

        $creditNoteTaxMinor = JournalEntryLine::toMinorUnits(
            CreditNote::query()
                ->where('status', CreditNoteStatus::Confirmed->value)
                ->whereDate('issue_date', '>=', $fromDate)
                ->whereDate('issue_date', '<=', $toDate)
                ->sum('tax_total')
        );

        $inputMinor = JournalEntryLine::toMinorUnits(
            TaxRecognitionEntry::query()
                ->where('direction', 'input')
                ->whereDate('tax_date', '>=', $fromDate)
                ->whereDate('tax_date', '<=', $toDate)
                ->sum('tax_amount')
        );

        return [
            'deferred' => $deferredMinor,
            'payable' => $payableMinor,
            'reversals' => $reversalsMinor,
            'credit_note_tax' => $creditNoteTaxMinor,
            'reversed' => $reversalsMinor + $creditNoteTaxMinor,
            'input' => $inputMinor,
        ];
    }

    /**
     * Reads each confirmed credit note's own journal entry rather than
     * re-deriving the deferred/payable split, because the split ratio is
     * frozen at posting time and is not persisted anywhere else (WP-2.7
     * ground truth). One join, not one query per credit note.
     *
     * @return array{deferred: int, payable: int}
     */
    private function confirmedCreditNoteJournalSplit(
        CarbonInterface $from,
        CarbonInterface $to,
        int $deferredAccountId,
        int $payableAccountId,
    ): array {
        $creditNoteIds = CreditNote::query()
            ->where('status', CreditNoteStatus::Confirmed->value)
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->pluck('id');

        $split = ['deferred' => 0, 'payable' => 0];

        if ($creditNoteIds->isEmpty()) {
            return $split;
        }

        $rows = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.source_type', CreditNote::class)
            ->whereIn('journal_entries.source_id', $creditNoteIds)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereIn('journal_entry_lines.chart_account_id', [$deferredAccountId, $payableAccountId])
            ->selectRaw('journal_entry_lines.chart_account_id as chart_account_id, SUM(journal_entry_lines.debit) as debit_total')
            ->groupBy('journal_entry_lines.chart_account_id')
            ->get();

        foreach ($rows as $row) {
            $accountId = is_numeric($row->chart_account_id) ? (int) $row->chart_account_id : 0;
            $minor = JournalEntryLine::toMinorUnits($row->debit_total);

            if ($accountId === $deferredAccountId) {
                $split['deferred'] = $minor;
            } elseif ($accountId === $payableAccountId) {
                $split['payable'] = $minor;
            }
        }

        return $split;
    }

    /**
     * A liability account's normal balance is credits minus debits (mirrors
     * {@see AccountsReceivableService::receivableControlAccountMinor()}'s
     * asset-side convention, inverted).
     */
    private function liabilityMovementMinor(int $chartAccountId, CarbonInterface $from, CarbonInterface $to): int
    {
        $totals = $this->accountTotals($chartAccountId, $from, $to);

        return JournalEntryLine::toMinorUnits(data_get($totals, 'credits'))
            - JournalEntryLine::toMinorUnits(data_get($totals, 'debits'));
    }

    /**
     * An asset account's normal balance is debits minus credits — the
     * recoverable input tax account (`1450`) increases on debit, unlike the
     * two liability tax accounts above.
     */
    private function assetMovementMinor(int $chartAccountId, CarbonInterface $from, CarbonInterface $to): int
    {
        $totals = $this->accountTotals($chartAccountId, $from, $to);

        return JournalEntryLine::toMinorUnits(data_get($totals, 'debits'))
            - JournalEntryLine::toMinorUnits(data_get($totals, 'credits'));
    }

    private function accountTotals(int $chartAccountId, CarbonInterface $from, CarbonInterface $to): object
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.chart_account_id', $chartAccountId)
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->whereDate('journal_entries.entry_date', '>=', $from->toDateString())
            ->whereDate('journal_entries.entry_date', '<=', $to->toDateString())
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) as credits, COALESCE(SUM(journal_entry_lines.debit), 0) as debits')
            ->first() ?? (object) ['credits' => 0, 'debits' => 0];
    }

    /**
     * One-line duplicate of {@see AccountingDocumentService::accountId()}'s
     * idiom for the one account that service resolves by code rather than
     * through {@see SalesAccountResolver} — not worth making that method
     * public for a single caller.
     */
    private function recoverableInputTaxAccountId(): ?int
    {
        $id = ChartAccount::query()
            ->where('code', '1450')
            ->where('is_postable', true)
            ->where('is_active', true)
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * A chart account's primary key is always numeric in this schema;
     * `Model::getKey()` is only typed `mixed` because Eloquent supports
     * non-integer keys in general.
     */
    private static function keyOf(ChartAccount $account): int
    {
        $key = $account->getKey();

        return is_numeric($key) ? (int) $key : 0;
    }

    /** @return AccountReconciliation */
    private static function accountReconciliation(int $registerMinor, int $journalMinor): array
    {
        return [
            'register' => self::money($registerMinor),
            'journal' => self::money($journalMinor),
            'difference' => self::money($journalMinor - $registerMinor),
        ];
    }

    private static function money(int $minorUnits): string
    {
        $absolute = abs($minorUnits);
        $value = sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);

        return $minorUnits < 0 ? '-'.$value : $value;
    }
}
