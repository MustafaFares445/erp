<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\CreditNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\RefundStatus;
use App\Enums\WriteOffStatus;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReceivableWriteOff;
use App\Models\Refund;
use App\Models\SalesSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Derived Accounts Receivable subledger.
 *
 * No customer balance is persisted here: invoice principal, confirmed credits,
 * posted payment allocations and approved write-offs remain the source of truth.
 *
 * @phpstan-type AgingBucket 'current'|'1_30'|'31_60'|'61_90'|'over_90'
 * @phpstan-type InvoiceRow array{
 *     invoice_id: int,
 *     customer_id: int,
 *     number: string,
 *     invoice_date: string,
 *     due_date: string,
 *     total_minor: int,
 *     credited_minor: int,
 *     paid_minor: int,
 *     written_off_minor: int,
 *     credit_balance_minor: int,
 *     net_receivable_minor: int,
 *     outstanding_minor: int
 * }
 * @phpstan-type AgingBuckets array{current: int, '1_30': int, '31_60': int, '61_90': int, over_90: int}
 * @phpstan-type CustomerSummary array{
 *     customer_id: int,
 *     customer_name: string,
 *     customer_deleted: bool,
 *     billed_minor: int,
 *     credited_minor: int,
 *     paid_minor: int,
 *     written_off_minor: int,
 *     credit_balance_minor: int,
 *     net_receivable_minor: int,
 *     outstanding_minor: int,
 *     buckets: AgingBuckets
 * }
 * @phpstan-type CustomerDocument array{
 *     invoice_id: int,
 *     customer_id: int,
 *     number: string,
 *     invoice_date: string,
 *     due_date: string,
 *     total_minor: int,
 *     credited_minor: int,
 *     paid_minor: int,
 *     written_off_minor: int,
 *     credit_balance_minor: int,
 *     net_receivable_minor: int,
 *     outstanding_minor: int,
 *     days_overdue: int
 * }
 * @phpstan-type CustomerDetail array{
 *     customer_id: int,
 *     customer_name: string,
 *     customer_deleted: bool,
 *     billed_minor: int,
 *     credited_minor: int,
 *     paid_minor: int,
 *     written_off_minor: int,
 *     credit_balance_minor: int,
 *     net_receivable_minor: int,
 *     outstanding_minor: int,
 *     buckets: AgingBuckets,
 *     documents: list<CustomerDocument>
 * }
 * @phpstan-type AgingSummary array{
 *     as_of: string,
 *     customers: list<CustomerSummary>,
 *     credit_customers: list<CustomerSummary>,
 *     billed_minor: int,
 *     credited_minor: int,
 *     paid_minor: int,
 *     written_off_minor: int,
 *     credit_balance_minor: int,
 *     net_receivable_minor: int,
 *     outstanding_minor: int,
 *     control_account_minor: int,
 *     tie_out_difference_minor: int,
 *     is_reconciled: bool
 * }
 * @phpstan-type StatementEntry array{date: string, type: string, reference: string, debit_minor: int, credit_minor: int}
 * @phpstan-type Statement array{
 *     customer_id: int,
 *     customer_name: string,
 *     from: string,
 *     to: string,
 *     brought_forward_minor: int,
 *     entries: list<StatementEntry>,
 *     carried_forward_minor: int
 * }
 */
final readonly class AccountsReceivableService
{
    /** @return AgingSummary */
    public function summary(?CarbonInterface $asOf = null): array
    {
        return $this->aging($asOf);
    }

    /** @return AgingSummary */
    public function aging(?CarbonInterface $asOf = null): array
    {
        $date = $this->asOfDate($asOf);
        $invoices = $this->invoiceRows($date);
        $creditAdjustments = $this->creditAdjustments($date);
        /** @var array<int, list<InvoiceRow>> $grouped */
        $grouped = [];

        foreach ($invoices as $invoice) {
            $grouped[(int) $invoice['customer_id']][] = $invoice;
        }

        foreach (array_keys($creditAdjustments) as $customerId) {
            $grouped[$customerId] ??= [];
        }

        $customerProfiles = CustomerProfile::withTrashed()
            ->whereKey(array_keys($grouped))
            ->get()
            ->keyBy('id');

        /** @var list<CustomerSummary> $customers */
        $customers = [];
        $creditCustomers = [];
        $billedMinor = 0;
        $creditedMinor = 0;
        $paidMinor = 0;
        $writtenOffMinor = 0;
        $creditBalanceMinor = 0;

        foreach ($grouped as $customerId => $customerInvoices) {
            $row = $this->customerSummary($customerId, $customerInvoices, $date, $customerProfiles, $creditAdjustments[$customerId] ?? 0);
            $billedMinor += $row['billed_minor'];
            $creditedMinor += $row['credited_minor'];
            $paidMinor += $row['paid_minor'];
            $writtenOffMinor += $row['written_off_minor'];
            $creditBalanceMinor += $row['credit_balance_minor'];

            if ($row['outstanding_minor'] > 0) {
                $customers[] = $row;
            } elseif ($row['credit_balance_minor'] > 0) {
                $creditCustomers[] = $row;
            }
        }

        usort($customers, static fn (array $left, array $right): int => $right['outstanding_minor'] <=> $left['outstanding_minor']);
        $outstandingMinor = array_sum(array_column($customers, 'outstanding_minor'));
        $netReceivableMinor = $outstandingMinor - $creditBalanceMinor;
        $controlMinor = $this->receivableControlAccountMinor($date);

        return [
            'as_of' => $date->toDateString(),
            'customers' => $customers,
            'credit_customers' => $creditCustomers,
            'billed_minor' => $billedMinor,
            'credited_minor' => $creditedMinor,
            'paid_minor' => $paidMinor,
            'written_off_minor' => $writtenOffMinor,
            'outstanding_minor' => $outstandingMinor,
            'credit_balance_minor' => $creditBalanceMinor,
            'net_receivable_minor' => $netReceivableMinor,
            'control_account_minor' => $controlMinor,
            'tie_out_difference_minor' => $netReceivableMinor - $controlMinor,
            'is_reconciled' => $netReceivableMinor === $controlMinor,
        ];
    }

    /** @return CustomerDetail */
    public function customerDetail(CustomerProfile $customer, ?CarbonInterface $asOf = null): array
    {
        $date = $this->asOfDate($asOf);
        $documents = array_values(array_filter(
            $this->invoiceRows($date),
            static fn (array $row): bool => (int) $row['customer_id'] === (int) $customer->id,
        ));

        $summary = $this->customerSummary((int) $customer->id, $documents, $date, creditAdjustmentMinor: $this->creditAdjustments($date)[(int) $customer->id] ?? 0);
        $summary['documents'] = array_map(function (array $document) use ($date): array {
            $document['days_overdue'] = $this->daysOverdue((string) $document['due_date'], $date);

            return $document;
        }, array_values(array_filter(
            $documents,
            static fn (array $document): bool => (int) $document['outstanding_minor'] > 0,
        )));

        return $summary;
    }

    public function toCsv(?CarbonInterface $asOf = null): string
    {
        $summary = $this->aging($asOf);
        /** @var resource $stream */
        $stream = fopen('php://temp', 'w+');

        fputcsv($stream, ['As of', $summary['as_of']], escape: '\\');
        fputcsv($stream, ['Customer', 'Billed', 'Credits', 'Paid', 'Written off', 'Outstanding', 'Credit balance', 'Net receivable', 'Current', '1-30', '31-60', '61-90', 'Over 90'], escape: '\\');
        foreach ([...$summary['customers'], ...$summary['credit_customers']] as $customer) {
            fputcsv($stream, [
                $customer['customer_name'],
                $this->formatMinor((int) $customer['billed_minor']),
                $this->formatMinor((int) $customer['credited_minor']),
                $this->formatMinor((int) $customer['paid_minor']),
                $this->formatMinor((int) $customer['written_off_minor']),
                $this->formatMinor((int) $customer['outstanding_minor']),
                $this->formatMinor((int) $customer['credit_balance_minor']),
                $this->formatMinor((int) $customer['net_receivable_minor']),
                $this->formatMinor((int) $customer['buckets']['current']),
                $this->formatMinor((int) $customer['buckets']['1_30']),
                $this->formatMinor((int) $customer['buckets']['31_60']),
                $this->formatMinor((int) $customer['buckets']['61_90']),
                $this->formatMinor((int) $customer['buckets']['over_90']),
            ], escape: '\\');
        }

        fputcsv($stream, [], escape: '\\');
        fputcsv($stream, ['Subledger outstanding', $this->formatMinor((int) $summary['outstanding_minor'])], escape: '\\');
        fputcsv($stream, ['Customer credit balance', $this->formatMinor((int) $summary['credit_balance_minor'])], escape: '\\');
        fputcsv($stream, ['Net receivable subledger', $this->formatMinor((int) $summary['net_receivable_minor'])], escape: '\\');
        fputcsv($stream, ['Receivable control account', $this->formatMinor((int) $summary['control_account_minor'])], escape: '\\');
        fputcsv($stream, ['Tie-out difference', $this->formatMinor((int) $summary['tie_out_difference_minor'])], escape: '\\');

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return is_string($csv) ? $csv : '';
    }

    public function receivableControlAccountMinor(?CarbonInterface $asOf = null): int
    {
        $accountId = $this->receivableControlAccountId();
        if ($accountId === null) {
            return 0;
        }

        $totalsQuery = DB::table((new JournalEntryLine)->getTable())
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) as debits, COALESCE(SUM(journal_entry_lines.credit), 0) as credits')
            ->join((new JournalEntry)->getTable(), 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entry_lines.chart_account_id', $accountId);

        if ($asOf instanceof CarbonInterface) {
            $totalsQuery->whereDate('journal_entries.entry_date', '<=', $asOf->toDateString());
        }

        $totals = $totalsQuery->first();

        return JournalEntryLine::toMinorUnits(data_get($totals, 'debits'))
            - JournalEntryLine::toMinorUnits(data_get($totals, 'credits'));
    }

    /**
     * @param  array{as_of: string, tie_out_difference_minor: int, net_receivable_minor: int, control_account_minor: int}|null  $agingSummary
     * @return array{as_of: string, subledger_minor: int, control_account_minor: int, difference_minor: int, is_reconciled: bool, candidate_causes: list<array{code: string, count: int, message: string}>}
     */
    public function reconciliation(?CarbonInterface $asOf = null, ?array $agingSummary = null): array
    {
        $summary = $agingSummary ?? $this->aging($asOf);
        $difference = (int) $summary['tie_out_difference_minor'];

        return [
            'as_of' => $summary['as_of'],
            'subledger_minor' => (int) $summary['net_receivable_minor'],
            'control_account_minor' => (int) $summary['control_account_minor'],
            'difference_minor' => $difference,
            'is_reconciled' => $difference === 0,
            'candidate_causes' => $difference === 0 ? [] : $this->candidateCauses(),
        ];
    }

    /** @return Statement */
    public function statement(CustomerProfile $customer, CarbonInterface $from, CarbonInterface $to): array
    {
        $fromDate = CarbonImmutable::instance($from)->startOfDay();
        $toDate = CarbonImmutable::instance($to)->endOfDay();
        if ($toDate->lessThan($fromDate)) {
            throw new LogicException('Statement end date must not be before the start date.');
        }

        $openingDate = $fromDate->subDay()->endOfDay();
        $opening = $this->customerOutstandingAt($customer, $openingDate);
        $closing = $this->customerOutstandingAt($customer, $toDate);
        $entries = $this->statementEntries($customer, $fromDate, $toDate);

        return [
            'customer_id' => (int) $customer->id,
            'customer_name' => $this->customerName($customer),
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'brought_forward_minor' => $opening,
            'entries' => $entries,
            'carried_forward_minor' => $closing,
        ];
    }

    private function asOfDate(?CarbonInterface $asOf): CarbonImmutable
    {
        return $asOf instanceof CarbonInterface
            ? CarbonImmutable::instance($asOf)->endOfDay()
            : CarbonImmutable::today()->endOfDay();
    }

    /** @return list<InvoiceRow> */
    private function invoiceRows(CarbonImmutable $asOf): array
    {
        $invoices = Invoice::query()
            ->withTrashed()
            ->with(['paymentAllocations.payment', 'creditNotes', 'writeOffs'])
            ->whereNotNull('issued_at')
            ->whereDate('issued_at', '<=', $asOf->toDateString())
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->get();

        $rows = [];
        foreach ($invoices as $invoice) {
            $totalMinor = JournalEntryLine::toMinorUnits($invoice->total_amount);
            $creditedMinor = $this->creditedMinor($invoice, $asOf);
            $paidMinor = $this->allocatedPaymentsMinor($invoice, $asOf);
            $writtenOffMinor = $this->writtenOffMinor($invoice, $asOf);
            $outstandingMinor = max(0, $totalMinor - $creditedMinor - $paidMinor - $writtenOffMinor);
            $creditBalanceMinor = max(0, $creditedMinor + $paidMinor + $writtenOffMinor - $totalMinor);

            $rows[] = [
                'invoice_id' => (int) $invoice->id,
                'customer_id' => (int) $invoice->customer_id,
                'number' => (string) $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'due_date' => ($invoice->due_date ?? $invoice->invoice_date)->toDateString(),
                'total_minor' => $totalMinor,
                'credited_minor' => $creditedMinor,
                'paid_minor' => $paidMinor,
                'written_off_minor' => $writtenOffMinor,
                'outstanding_minor' => $outstandingMinor,
                'credit_balance_minor' => $creditBalanceMinor,
                'net_receivable_minor' => $outstandingMinor - $creditBalanceMinor,
            ];
        }

        return $rows;
    }

    private function creditedMinor(Invoice $invoice, CarbonImmutable $asOf): int
    {
        return $invoice->creditNotes
            ->filter(static fn (CreditNote $credit): bool => in_array($credit->status, [CreditNoteStatus::Confirmed, CreditNoteStatus::Reversed], true)
                && $credit->confirmed_at !== null
                && $credit->confirmed_at->lessThanOrEqualTo($asOf)
                && ($credit->reversed_at === null || $credit->reversed_at->greaterThan($asOf)))
            ->sum(static fn (CreditNote $credit): int => JournalEntryLine::toMinorUnits($credit->grand_total));
    }

    private function allocatedPaymentsMinor(Invoice $invoice, CarbonImmutable $asOf): int
    {
        return $invoice->paymentAllocations
            ->filter(static function (PaymentAllocation $allocation) use ($asOf): bool {
                $payment = $allocation->payment;

                return $payment !== null
                    && $payment->posted_at !== null
                    && $payment->posted_at->lessThanOrEqualTo($asOf)
                    && ($payment->reversed_at === null || $payment->reversed_at->greaterThan($asOf));
            })
            ->sum(static fn (PaymentAllocation $allocation): int => JournalEntryLine::toMinorUnits($allocation->amount));
    }

    private function writtenOffMinor(Invoice $invoice, CarbonImmutable $asOf): int
    {
        return $invoice->writeOffs
            ->filter(static fn (ReceivableWriteOff $writeOff): bool => $writeOff->status === WriteOffStatus::Approved
                && $writeOff->approved_at !== null
                && $writeOff->approved_at->lessThanOrEqualTo($asOf))
            ->sum(static fn (ReceivableWriteOff $writeOff): int => $writeOff->amount_minor);
    }

    /** @return array<int, int> */
    private function creditAdjustments(CarbonImmutable $asOf): array
    {
        $adjustments = [];
        $credits = CreditNote::withTrashed()
            ->whereNull('invoice_id')
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '<=', $asOf)
            ->where(fn (Builder $query): Builder => $query->whereNull('reversed_at')->orWhere('reversed_at', '>', $asOf))
            ->get();

        foreach ($credits as $credit) {
            $adjustments[$credit->customer_id] = ($adjustments[$credit->customer_id] ?? 0)
                + JournalEntryLine::toMinorUnits($credit->grand_total);
        }

        $refunds = Refund::withTrashed()
            ->where('status', RefundStatus::Paid->value)
            ->whereHas('journalEntry', fn (Builder $query): Builder => $query
                ->where('status', 'posted')
                ->whereDate('entry_date', '<=', $asOf->toDateString()))
            ->get();

        foreach ($refunds as $refund) {
            $receivableMinor = JournalEntryLine::toMinorUnits($refund->amount)
                - JournalEntryLine::toMinorUnits($refund->customer_deposit_amount);
            $adjustments[$refund->customer_id] = ($adjustments[$refund->customer_id] ?? 0) - $receivableMinor;
        }

        return $adjustments;
    }

    /**
     * @param  list<InvoiceRow>  $documents
     * @param  Collection<int, CustomerProfile>|null  $customerProfiles
     * @return CustomerSummary
     */
    private function customerSummary(
        int $customerId,
        array $documents,
        CarbonImmutable $asOf,
        ?Collection $customerProfiles = null,
        int $creditAdjustmentMinor = 0,
    ): array {
        $customer = $customerProfiles?->get($customerId) ?? CustomerProfile::withTrashed()->find($customerId);
        $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        $billedMinor = 0;
        $creditedMinor = 0;
        $paidMinor = 0;
        $writtenOffMinor = 0;
        $outstandingMinor = 0;
        $creditBalanceMinor = $creditAdjustmentMinor;

        foreach ($documents as $document) {
            $billedMinor += (int) $document['total_minor'];
            $creditedMinor += (int) $document['credited_minor'];
            $paidMinor += (int) $document['paid_minor'];
            $writtenOffMinor += (int) $document['written_off_minor'];
            $remaining = (int) $document['outstanding_minor'];
            $outstandingMinor += $remaining;
            $creditBalanceMinor += $document['credit_balance_minor'];
            if ($remaining <= 0) {
                continue;
            }

            $days = $this->daysOverdue((string) $document['due_date'], $asOf);
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => 'over_90',
            };
            $buckets[$bucket] += $remaining;
        }

        return [
            'customer_id' => $customerId,
            'customer_name' => $customer instanceof CustomerProfile ? $this->customerName($customer) : "Deleted customer #{$customerId}",
            'customer_deleted' => ! $customer instanceof CustomerProfile || $customer->trashed(),
            'billed_minor' => $billedMinor,
            'credited_minor' => $creditedMinor,
            'paid_minor' => $paidMinor,
            'written_off_minor' => $writtenOffMinor,
            'outstanding_minor' => $outstandingMinor,
            'credit_balance_minor' => $creditBalanceMinor,
            'net_receivable_minor' => $outstandingMinor - $creditBalanceMinor,
            'buckets' => $buckets,
        ];
    }

    /** @return list<array{code: string, count: int, message: string}> */
    private function candidateCauses(): array
    {
        $causes = [];
        $unposted = PaymentAllocation::query()->whereHas('payment', fn (Builder $query): Builder => $query->whereNull('posted_at'))->count();
        if ($unposted > 0) {
            $causes[] = ['code' => 'unposted_payments', 'count' => $unposted, 'message' => 'Payment allocations exist on payments that have not been posted.'];
        }

        $cancelled = PaymentAllocation::query()->whereHas('invoice', fn (Builder $query): Builder => $query->where('status', InvoiceStatus::Cancelled->value))->count();
        if ($cancelled > 0) {
            $causes[] = ['code' => 'cancelled_invoice_allocations', 'count' => $cancelled, 'message' => 'Payment allocations exist against cancelled invoices.'];
        }

        $accountId = $this->receivableControlAccountId();
        if ($accountId !== null) {
            $direct = JournalEntryLine::query()
                ->where('chart_account_id', $accountId)
                ->whereHas('journalEntry', fn (Builder $query): Builder => $query->where('status', 'posted')->where(function (Builder $query): void {
                    $query->whereNull('source_type')
                        ->orWhereNotIn('source_type', [Invoice::class, Payment::class, CreditNote::class, ReceivableWriteOff::class]);
                }))
                ->count();
            if ($direct > 0) {
                $causes[] = ['code' => 'direct_ar_journals', 'count' => $direct, 'message' => 'Posted journal lines hit the Accounts Receivable control account outside the normal receivable documents.'];
            }
        }

        return $causes;
    }

    private function receivableControlAccountId(): ?int
    {
        $configuredId = SalesSetting::query()->value('receivable_account_id');
        if (is_numeric($configuredId)) {
            return (int) $configuredId;
        }

        $fallbackId = ChartAccount::query()->where('code', '1200')->value('id');

        return is_numeric($fallbackId) ? (int) $fallbackId : null;
    }

    private function customerOutstandingAt(CustomerProfile $customer, CarbonImmutable $asOf): int
    {
        return array_sum(array_map(
            static fn (array $row): int => (int) $row['net_receivable_minor'],
            array_filter($this->invoiceRows($asOf), static fn (array $row): bool => (int) $row['customer_id'] === (int) $customer->id),
        )) - ($this->creditAdjustments($asOf)[(int) $customer->id] ?? 0);
    }

    /** @return list<StatementEntry> */
    private function statementEntries(CustomerProfile $customer, CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var list<StatementEntry> $entries */
        $entries = [];

        Invoice::query()->where('customer_id', $customer->id)->whereNotNull('issued_at')
            ->whereBetween('issued_at', [$from, $to])->where('status', '!=', InvoiceStatus::Cancelled->value)->get()
            ->each(function (Invoice $invoice) use (&$entries): void {
                /** @var CarbonInterface $issuedAt */
                $issuedAt = $invoice->issued_at;

                $entries[] = ['date' => $issuedAt->toDateString(), 'type' => 'invoice', 'reference' => $invoice->invoice_number, 'debit_minor' => JournalEntryLine::toMinorUnits($invoice->total_amount), 'credit_minor' => 0];
            });

        PaymentAllocation::query()->with('payment')->whereHas('invoice', fn (Builder $query): Builder => $query->where('customer_id', $customer->id))
            ->whereHas('payment', fn (Builder $query): Builder => $query->whereNotNull('posted_at')->whereBetween('posted_at', [$from, $to]))->get()
            ->each(function (PaymentAllocation $allocation) use (&$entries): void {
                /** @var Payment $payment */
                $payment = $allocation->payment;

                if ($payment->isReversed()) {
                    return;
                }

                /** @var CarbonInterface $postedAt */
                $postedAt = $payment->posted_at;

                $entries[] = ['date' => $postedAt->toDateString(), 'type' => 'payment', 'reference' => $payment->payment_number, 'debit_minor' => 0, 'credit_minor' => JournalEntryLine::toMinorUnits($allocation->amount)];
            });

        CreditNote::query()->where('customer_id', $customer->id)->whereNotNull('confirmed_at')
            ->whereBetween('confirmed_at', [$from, $to])->get()->each(function (CreditNote $credit) use (&$entries): void {
                /** @var CarbonInterface $confirmedAt */
                $confirmedAt = $credit->confirmed_at;

                $entries[] = ['date' => $confirmedAt->toDateString(), 'type' => 'credit_note', 'reference' => $credit->credit_note_number, 'debit_minor' => 0, 'credit_minor' => JournalEntryLine::toMinorUnits($credit->grand_total)];
            });

        CreditNote::query()->where('customer_id', $customer->id)->whereBetween('reversed_at', [$from, $to])->get()
            ->each(function (CreditNote $credit) use (&$entries): void {
                $entries[] = ['date' => CarbonImmutable::parse($credit->reversed_at)->toDateString(), 'type' => 'credit_note_reversal', 'reference' => $credit->credit_note_number, 'debit_minor' => JournalEntryLine::toMinorUnits($credit->grand_total), 'credit_minor' => 0];
            });

        Refund::query()->with('journalEntry')->where('customer_id', $customer->id)->where('status', RefundStatus::Paid->value)
            ->whereHas('journalEntry', fn (Builder $query): Builder => $query->where('status', 'posted')
                ->whereDate('entry_date', '>=', $from->toDateString())
                ->whereDate('entry_date', '<=', $to->toDateString()))
            ->get()->each(function (Refund $refund) use (&$entries): void {
                $receivableMinor = JournalEntryLine::toMinorUnits($refund->amount) - JournalEntryLine::toMinorUnits($refund->customer_deposit_amount);
                if ($receivableMinor === 0) {
                    return;
                }

                $journal = $refund->journalEntry ?? throw new LogicException('A posted receivable refund must have a journal entry.');

                $entries[] = ['date' => $journal->entry_date->toDateString(), 'type' => 'refund', 'reference' => $refund->refund_number, 'debit_minor' => $receivableMinor, 'credit_minor' => 0];
            });

        ReceivableWriteOff::query()->where('customer_id', $customer->id)->where('status', WriteOffStatus::Approved->value)
            ->whereBetween('approved_at', [$from, $to])->get()->each(function (ReceivableWriteOff $writeOff) use (&$entries): void {
                /** @var CarbonInterface $approvedAt */
                $approvedAt = $writeOff->approved_at;

                $entries[] = ['date' => $approvedAt->toDateString(), 'type' => 'write_off', 'reference' => $writeOff->write_off_number, 'debit_minor' => 0, 'credit_minor' => (int) $writeOff->amount_minor];
            });

        usort($entries, static fn (array $left, array $right): int => [$left['date'], $left['type']] <=> [$right['date'], $right['type']]);

        return $entries;
    }

    private function daysOverdue(string $dueDate, CarbonImmutable $asOf): int
    {
        return (int) max(0, CarbonImmutable::parse($dueDate)->diffInDays($asOf, false));
    }

    private function customerName(CustomerProfile $customer): string
    {
        return $customer->company_name ?: ($customer->customer_code ?: "Customer #{$customer->id}");
    }

    private function formatMinor(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
