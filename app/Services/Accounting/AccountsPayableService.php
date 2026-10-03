<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\BillStatus;
use App\Enums\ExpenseStatus;
use App\Enums\SupplierPaymentStatus;
use App\Models\Bill;
use App\Models\ChartAccount;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Computes the payable subledger from posted source documents and settlement
 * evidence as it existed at the requested as-of date.
 *
 * @phpstan-type AgingBucket 'current'|'1_30'|'31_60'|'61_90'|'over_90'
 * @phpstan-type DocumentRow array{
 *     type: string,
 *     document_id: int,
 *     supplier_id: int,
 *     number: string,
 *     supplier_reference: ?string,
 *     date: string,
 *     recognised_on: string,
 *     due_date: string,
 *     total_minor: int,
 *     paid_minor: int,
 *     remaining_minor: int
 * }
 * @phpstan-type SupplierRow array{
 *     supplier_id: int,
 *     supplier_name: string,
 *     supplier_deleted: bool,
 *     billed_minor: int,
 *     paid_minor: int,
 *     outstanding_minor: int,
 *     buckets: array{current: int, '1_30': int, '31_60': int, '61_90': int, over_90: int}
 * }
 * @phpstan-type SupplierStatementEntry array{date: string, type: string, reference: string, charge_minor: int, payment_minor: int}
 * @phpstan-type SupplierStatement array{
 *     supplier_id: int,
 *     supplier_name: string,
 *     from: string,
 *     to: string,
 *     brought_forward_minor: int,
 *     entries: list<SupplierStatementEntry>,
 *     carried_forward_minor: int
 * }
 */
final readonly class AccountsPayableService
{
    /** @return array{as_of: string, suppliers: list<SupplierRow>, billed_minor: int, paid_minor: int, outstanding_minor: int, control_account_minor: int, tie_out_difference_minor: int, is_reconciled: bool} */
    public function summary(?CarbonInterface $asOf = null): array
    {
        return $this->aging($asOf);
    }

    public function toCsv(?CarbonInterface $asOf = null): string
    {
        $summary = $this->aging($asOf);
        /** @var resource $stream */
        $stream = fopen('php://temp', 'w+');

        fputcsv($stream, ['As of', $summary['as_of']], escape: '\\');
        fputcsv($stream, ['Supplier', 'Billed', 'Paid', 'Outstanding', 'Current', '1-30', '31-60', '61-90', 'Over 90'], escape: '\\');
        foreach ($summary['suppliers'] as $supplier) {
            fputcsv($stream, [
                $supplier['supplier_name'],
                $this->formatMinor((int) $supplier['billed_minor']),
                $this->formatMinor((int) $supplier['paid_minor']),
                $this->formatMinor((int) $supplier['outstanding_minor']),
                $this->formatMinor((int) $supplier['buckets']['current']),
                $this->formatMinor((int) $supplier['buckets']['1_30']),
                $this->formatMinor((int) $supplier['buckets']['31_60']),
                $this->formatMinor((int) $supplier['buckets']['61_90']),
                $this->formatMinor((int) $supplier['buckets']['over_90']),
            ], escape: '\\');
        }

        fputcsv($stream, [], escape: '\\');
        fputcsv($stream, ['Subledger outstanding', $this->formatMinor((int) $summary['outstanding_minor'])], escape: '\\');
        fputcsv($stream, ['Payable control account', $this->formatMinor((int) $summary['control_account_minor'])], escape: '\\');
        fputcsv($stream, ['Tie-out difference', $this->formatMinor((int) $summary['tie_out_difference_minor'])], escape: '\\');

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return is_string($csv) ? $csv : '';
    }

    /**
     * @return array{
     *     as_of: string,
     *     suppliers: list<SupplierRow>,
     *     billed_minor: int,
     *     paid_minor: int,
     *     outstanding_minor: int,
     *     control_account_minor: int,
     *     tie_out_difference_minor: int,
     *     is_reconciled: bool
     * }
     */
    public function aging(?CarbonInterface $asOf = null): array
    {
        $date = $asOf instanceof CarbonInterface
            ? CarbonImmutable::instance($asOf)->endOfDay()
            : CarbonImmutable::today()->endOfDay();
        $documents = $this->documents($date);
        /** @var array<int, list<DocumentRow>> $groupedDocuments */
        $groupedDocuments = [];

        foreach ($documents as $document) {
            $groupedDocuments[$document['supplier_id']][] = $document;
        }

        /** @var list<SupplierRow> $suppliers */
        $suppliers = [];
        $billedMinor = 0;
        $paidMinor = 0;

        foreach ($groupedDocuments as $supplierId => $supplierDocuments) {
            $summary = $this->supplierSummary($supplierId, $supplierDocuments, $date);
            $billedMinor += $summary['billed_minor'];
            $paidMinor += $summary['paid_minor'];

            if ($summary['outstanding_minor'] === 0) {
                continue;
            }

            $suppliers[] = $summary;
        }

        usort($suppliers, static fn (array $left, array $right): int => $right['outstanding_minor'] <=> $left['outstanding_minor']);

        $outstandingMinor = 0;
        foreach ($suppliers as $supplier) {
            $outstandingMinor += $supplier['outstanding_minor'];
        }

        $controlMinor = $this->payableControlAccountMinor($date);

        return [
            'as_of' => $date->toDateString(),
            'suppliers' => $suppliers,
            'billed_minor' => $billedMinor,
            'paid_minor' => $paidMinor,
            'outstanding_minor' => $outstandingMinor,
            'control_account_minor' => $controlMinor,
            'tie_out_difference_minor' => $outstandingMinor - $controlMinor,
            'is_reconciled' => $outstandingMinor === $controlMinor,
        ];
    }

    /** @return array<string, mixed> */
    public function supplierDetail(Supplier $supplier, ?CarbonInterface $asOf = null): array
    {
        $date = $asOf instanceof CarbonInterface
            ? CarbonImmutable::instance($asOf)->endOfDay()
            : CarbonImmutable::today()->endOfDay();
        $supplierId = $supplier->id;
        $documents = [];

        foreach ($this->documents($date) as $document) {
            if ($document['supplier_id'] !== $supplierId) {
                continue;
            }
            if ($document['remaining_minor'] <= 0) {
                continue;
            }
            $documents[] = $document;
        }

        $summary = $this->supplierSummary($supplierId, $documents, $date);
        $summary['documents'] = [];

        foreach ($documents as $document) {
            $summary['documents'][] = [
                'type' => $document['type'],
                'document_id' => $document['document_id'],
                'number' => $document['number'],
                'supplier_reference' => $document['supplier_reference'],
                'date' => $document['date'],
                'due_date' => $document['due_date'],
                'days_overdue' => $this->daysOverdue($document['due_date'], $date),
                'total_minor' => $document['total_minor'],
                'paid_minor' => $document['paid_minor'],
                'remaining_minor' => $document['remaining_minor'],
            ];
        }

        return $summary;
    }

    /** @return SupplierStatement */
    public function statement(Supplier $supplier, CarbonInterface $from, CarbonInterface $to): array
    {
        $fromDate = CarbonImmutable::instance($from)->startOfDay();
        $toDate = CarbonImmutable::instance($to)->endOfDay();
        if ($toDate->lessThan($fromDate)) {
            throw new \LogicException('Statement end date must not be before the start date.');
        }

        $entries = [];
        foreach ($this->documents($toDate) as $document) {
            if ($document['supplier_id'] !== (int) $supplier->id) {
                continue;
            }

            $recognisedOn = CarbonImmutable::parse($document['recognised_on']);
            if ($recognisedOn->lessThan($fromDate)) {
                continue;
            }
            if ($recognisedOn->greaterThan($toDate)) {
                continue;
            }

            $entries[] = [
                'date' => $document['recognised_on'],
                'type' => $document['type'],
                'reference' => $document['number'],
                'charge_minor' => $document['total_minor'],
                'payment_minor' => 0,
            ];
        }

        SupplierPayment::query()
            ->with('allocations')
            ->where('supplier_id', $supplier->id)
            ->where('status', SupplierPaymentStatus::Paid->value)
            ->whereBetween('payment_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->get()
            ->each(function (SupplierPayment $payment) use (&$entries): void {
                $allocatedMinor = $payment->allocations->sum(
                    static fn (SupplierPaymentAllocation $allocation): int => JournalEntryLine::toMinorUnits($allocation->amount),
                );
                if ($allocatedMinor <= 0) {
                    return;
                }

                $entries[] = [
                    'date' => $payment->payment_date->toDateString(),
                    'type' => 'supplier_payment',
                    'reference' => $payment->supplier_payment_number,
                    'charge_minor' => 0,
                    'payment_minor' => $allocatedMinor,
                ];
            });

        Expense::query()
            ->withTrashed()
            ->where('supplier_id', $supplier->id)
            ->whereNotNull('payment_date')
            ->whereBetween('payment_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->whereIn('status', [ExpenseStatus::Approved->value, ExpenseStatus::Paid->value])
            ->get()
            ->each(function (Expense $expense) use (&$entries): void {
                $paymentMinor = JournalEntryLine::toMinorUnits($expense->amount_paid);
                if ($paymentMinor <= 0) {
                    return;
                }

                $entries[] = [
                    'date' => $expense->payment_date?->toDateString() ?? $expense->expense_date->toDateString(),
                    'type' => 'expense_payment',
                    'reference' => (string) $expense->expense_number,
                    'charge_minor' => 0,
                    'payment_minor' => $paymentMinor,
                ];
            });

        usort($entries, static fn (array $left, array $right): int => [$left['date'], $left['type'], $left['reference']] <=> [$right['date'], $right['type'], $right['reference']]);

        return [
            'supplier_id' => (int) $supplier->id,
            'supplier_name' => (string) $supplier->name,
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'brought_forward_minor' => $this->supplierOutstandingAt($supplier, $fromDate->subDay()->endOfDay()),
            'entries' => $entries,
            'carried_forward_minor' => $this->supplierOutstandingAt($supplier, $toDate),
        ];
    }

    public function payableControlAccountMinor(?CarbonInterface $asOf = null): int
    {
        $accountId = DB::table((new ChartAccount)->getTable())->where('code', '2100')->value('id');
        if (! is_numeric($accountId)) {
            return 0;
        }

        $totalsQuery = DB::table((new JournalEntryLine)->getTable())
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) as credits, COALESCE(SUM(journal_entry_lines.debit), 0) as debits')
            ->join((new JournalEntry)->getTable(), 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entry_lines.chart_account_id', (int) $accountId);

        if ($asOf instanceof CarbonInterface) {
            $totalsQuery->whereDate('journal_entries.entry_date', '<=', $asOf->toDateString());
        }

        $totals = $totalsQuery->first();

        return JournalEntryLine::toMinorUnits(data_get($totals, 'credits'))
            - JournalEntryLine::toMinorUnits(data_get($totals, 'debits'));
    }

    /** @return list<DocumentRow> */
    private function documents(CarbonImmutable $asOf): array
    {
        /** @var list<DocumentRow> $documents */
        $documents = [];

        $bills = Bill::query()
            ->withTrashed()
            ->with(['journalEntry', 'paymentAllocations.supplierPayment'])
            ->whereIn('status', [
                BillStatus::Approved->value,
                BillStatus::PartiallyPaid->value,
                BillStatus::Paid->value,
            ])
            ->get();

        foreach ($bills as $bill) {
            if (! $this->wasRecognisedBy($bill->journalEntry, $bill->bill_date, $asOf)) {
                continue;
            }

            $paidMinor = $bill->paymentAllocations
                ->filter(static function (SupplierPaymentAllocation $allocation) use ($asOf): bool {
                    $payment = $allocation->supplierPayment;

                    return $payment instanceof SupplierPayment
                        && $payment->status === SupplierPaymentStatus::Paid
                        && $payment->payment_date->lessThanOrEqualTo($asOf);
                })
                ->sum(static fn (SupplierPaymentAllocation $allocation): int => JournalEntryLine::toMinorUnits($allocation->amount));

            $totalMinor = JournalEntryLine::toMinorUnits($bill->grandTotal());

            $documents[] = [
                'type' => 'bill',
                'document_id' => (int) $bill->id,
                'supplier_id' => (int) $bill->resolved_supplier_id,
                'number' => (string) $bill->bill_number,
                'supplier_reference' => $bill->supplier_reference,
                'date' => $bill->bill_date->toDateString(),
                'recognised_on' => $bill->journalEntry instanceof JournalEntry
                    ? $bill->journalEntry->entry_date->toDateString()
                    : $bill->bill_date->toDateString(),
                'due_date' => ($bill->due_date ?? $bill->bill_date)->toDateString(),
                'total_minor' => $totalMinor,
                'paid_minor' => min($totalMinor, $paidMinor),
                'remaining_minor' => max(0, $totalMinor - $paidMinor),
            ];
        }

        $expenses = Expense::query()
            ->withTrashed()
            ->with('journalEntry')
            ->whereIn('status', [
                ExpenseStatus::Approved->value,
                ExpenseStatus::Paid->value,
            ])
            ->whereNotNull('supplier_id')
            ->get();

        foreach ($expenses as $expense) {
            if (! $this->wasRecognisedBy($expense->journalEntry, $expense->expense_date, $asOf)) {
                continue;
            }

            $totalMinor = JournalEntryLine::toMinorUnits($expense->total_amount);
            $paidMinor = $expense->payment_date !== null && $expense->payment_date->lessThanOrEqualTo($asOf)
                ? min($totalMinor, JournalEntryLine::toMinorUnits($expense->amount_paid))
                : 0;

            $documents[] = [
                'type' => 'expense',
                'document_id' => (int) $expense->id,
                'supplier_id' => (int) $expense->supplier_id,
                'number' => (string) $expense->expense_number,
                'supplier_reference' => null,
                'date' => $expense->expense_date->toDateString(),
                'recognised_on' => $expense->journalEntry instanceof JournalEntry
                    ? $expense->journalEntry->entry_date->toDateString()
                    : $expense->expense_date->toDateString(),
                'due_date' => ($expense->due_date ?? $expense->expense_date)->toDateString(),
                'total_minor' => $totalMinor,
                'paid_minor' => $paidMinor,
                'remaining_minor' => max(0, $totalMinor - $paidMinor),
            ];
        }

        return $documents;
    }

    private function wasRecognisedBy(
        ?JournalEntry $entry,
        CarbonInterface $documentDate,
        CarbonImmutable $asOf,
    ): bool {
        if (! $entry instanceof JournalEntry) {
            return CarbonImmutable::instance($documentDate)->endOfDay()->lessThanOrEqualTo($asOf);
        }

        return $entry->getRawOriginal('status') === 'posted'
            && $entry->entry_date->lessThanOrEqualTo($asOf);
    }

    /**
     * @param  list<DocumentRow>  $documents
     * @return SupplierRow
     */
    private function supplierSummary(int $supplierId, array $documents, CarbonImmutable $date): array
    {
        $supplier = Supplier::withTrashed()->find($supplierId);
        /** @var array{current: int, '1_30': int, '31_60': int, '61_90': int, over_90: int} $buckets */
        $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        $billedMinor = 0;
        $paidMinor = 0;
        $outstandingMinor = 0;

        foreach ($documents as $document) {
            $billedMinor += $document['total_minor'];
            $paidMinor += $document['paid_minor'];
            $remaining = (int) $document['remaining_minor'];
            $outstandingMinor += $remaining;

            if ($remaining <= 0) {
                continue;
            }

            $days = $this->daysOverdue($document['due_date'], $date);
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => 'over_90',
            };
            /** @var AgingBucket $bucket */
            $buckets[$bucket] += $remaining;
        }

        $supplierName = "Deleted supplier #{$supplierId}";
        $supplierDeleted = true;

        if ($supplier instanceof Supplier) {
            $supplierName = $supplier->name;
            $supplierDeleted = $supplier->trashed();
        }

        return [
            'supplier_id' => $supplierId,
            'supplier_name' => $supplierName,
            'supplier_deleted' => $supplierDeleted,
            'billed_minor' => $billedMinor,
            'paid_minor' => $paidMinor,
            'outstanding_minor' => $outstandingMinor,
            'buckets' => $buckets,
        ];
    }

    private function supplierOutstandingAt(Supplier $supplier, CarbonImmutable $asOf): int
    {
        $supplierId = (int) $supplier->id;

        return array_sum(array_map(
            static fn (array $document): int => $document['supplier_id'] === $supplierId ? (int) $document['remaining_minor'] : 0,
            $this->documents($asOf),
        ));
    }

    private function daysOverdue(?string $dueDate, CarbonImmutable $asOf): int
    {
        if ($dueDate === null) {
            return 0;
        }

        return (int) max(0, CarbonImmutable::parse($dueDate)->diffInDays($asOf, false));
    }

    private function formatMinor(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
