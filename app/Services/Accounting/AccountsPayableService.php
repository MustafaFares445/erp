<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Bill;
use App\Models\ChartAccount;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Computes the payable subledger. No supplier balance is stored: every result
 * is derived from approved documents, allocations, and posted journal lines.
 *
 * @phpstan-type AgingBucket 'current'|'1_30'|'31_60'|'61_90'|'over_90'
 * @phpstan-type DocumentRow array{
 *     type: string,
 *     supplier_id: int,
 *     number: string,
 *     supplier_reference: ?string,
 *     date: string,
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
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new LogicException('The Accounts Payable export stream could not be opened.');
        }

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
            ],
                escape: '\\');
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
        $date = $asOf instanceof CarbonInterface ? CarbonImmutable::instance($asOf) : CarbonImmutable::today();
        $documents = $this->documents();
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

        $controlMinor = $this->payableControlAccountMinor();

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
        $date = $asOf instanceof CarbonInterface ? CarbonImmutable::instance($asOf) : CarbonImmutable::today();
        $supplierId = $supplier->id;
        $documents = [];
        foreach ($this->documents() as $document) {
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

    public function payableControlAccountMinor(): int
    {
        $accountId = DB::table((new ChartAccount)->getTable())->where('code', '2100')->value('id');
        if (! is_numeric($accountId)) {
            return 0;
        }

        $totals = DB::table((new JournalEntryLine)->getTable())
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) as credits, COALESCE(SUM(journal_entry_lines.debit), 0) as debits')
            ->join((new JournalEntry)->getTable(), 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entry_lines.chart_account_id', (int) $accountId)
            ->first();

        return JournalEntryLine::toMinorUnits(data_get($totals, 'credits'))
            - JournalEntryLine::toMinorUnits(data_get($totals, 'debits'));
    }

    /** @return list<DocumentRow> */
    private function documents(): array
    {
        /** @var list<DocumentRow> $documents */
        $documents = [];

        $bills = Bill::query()
            ->withTrashed()
            ->whereIn('status', ['approved', 'partially_paid', 'paid'])
            ->get();

        foreach ($bills as $bill) {
            $documents[] = [
                'type' => 'bill',
                'supplier_id' => (int) $bill->supplier_id,
                'number' => (string) $bill->bill_number,
                'supplier_reference' => $bill->supplier_reference,
                'date' => $bill->bill_date->toDateString(),
                'due_date' => ($bill->due_date ?? $bill->bill_date)->toDateString(),
                'total_minor' => JournalEntryLine::toMinorUnits($bill->grandTotal()),
                'paid_minor' => JournalEntryLine::toMinorUnits($bill->paidAmount()),
                'remaining_minor' => 0,
            ];
        }

        $expenses = Expense::query()
            ->withTrashed()
            ->whereIn('status', ['approved', 'paid'])
            ->whereNotNull('supplier_id')
            ->get();

        foreach ($expenses as $expense) {
            $documents[] = [
                'type' => 'expense',
                'supplier_id' => (int) $expense->supplier_id,
                'number' => (string) $expense->expense_number,
                'supplier_reference' => null,
                'date' => $expense->expense_date->toDateString(),
                'due_date' => ($expense->due_date ?? $expense->expense_date)->toDateString(),
                'total_minor' => JournalEntryLine::toMinorUnits($expense->total_amount),
                'paid_minor' => JournalEntryLine::toMinorUnits($expense->amount_paid),
                'remaining_minor' => 0,
            ];
        }

        foreach ($documents as $index => $document) {
            $documents[$index]['remaining_minor'] = max(0, $document['total_minor'] - $document['paid_minor']);
        }

        return $documents;
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
