<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only, dated accounting proof for the synthetic development company.
 *
 * Uses the real Accounting subledger services, not independently recalculated
 * balances; never changes posted financial documents or fiscal period status.
 */
final class AuditDemoFinanceCommand extends Command
{
    protected $signature = 'demo:finance-audit
        {--anchor= : As-of YYYY-MM-DD (default today)}
        {--months=12 : Number of month-end snapshots including the anchor month (1-24)}';

    protected $description = 'Audit twelve-month AR/AP control tie-outs and identify legacy orphan source postings (read-only).';

    public function handle(AccountsReceivableService $receivables, AccountsPayableService $payables): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Finance demo audits may run only in a local or testing environment.');

            return self::FAILURE;
        }

        $connection = DB::getDefaultConnection();
        $host = config("database.connections.{$connection}.host");
        if (! in_array($connection, ['mysql', 'sqlite'], true)
            || ($connection === 'mysql' && ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true))) {
            $this->error('Finance demo audits require a local database connection.');

            return self::FAILURE;
        }

        $rawAnchor = $this->option('anchor');
        $date = is_string($rawAnchor) && $rawAnchor !== '' ? $rawAnchor : CarbonImmutable::today()->toDateString();
        $monthsOption = $this->option('months');
        $months = is_scalar($monthsOption) && ctype_digit((string) $monthsOption) ? (int) $monthsOption : 0;

        try {
            $anchor = CarbonImmutable::createFromFormat('!Y-m-d', $date);
            if (! $anchor instanceof CarbonImmutable || $anchor->toDateString() !== $date || $months < 1 || $months > 24) {
                throw new \InvalidArgumentException('Invalid date or month count.');
            }
        } catch (Throwable) {
            $this->error('Use --anchor=YYYY-MM-DD and --months=1..24.');

            return self::FAILURE;
        }

        $database = config("database.connections.{$connection}.database");
        $this->info(sprintf('Read-only finance audit: %s / %s / %d months',
            $connection, is_string($database) ? $database : '(unknown)', $months));
        $this->line('As of        AR subledger  AR control   AR delta     AP subledger  AP control   AP delta     Status');

        $failures = 0;
        $begin = $anchor->startOfMonth()->subMonthsNoOverflow($months - 1);

        for ($index = 0; $index < $months; $index++) {
            $month = $begin->addMonthsNoOverflow($index);
            $asOf = $month->isSameMonth($anchor) ? $anchor : $month->endOfMonth();
            $ar = $receivables->reconciliation($asOf);
            $ap = $payables->summary($asOf);
            $arDifference = (int) $ar['difference_minor'];
            $apDifference = (int) $ap['tie_out_difference_minor'];
            $passed = $arDifference === 0 && $apDifference === 0;
            $failures += (int) ! $passed;

            $this->line(sprintf('%s  %12s  %10s  %10s  %12s  %10s  %10s  %s',
                $asOf->toDateString(),
                self::money((int) $ar['subledger_minor']),
                self::money((int) $ar['control_account_minor']),
                self::money($arDifference),
                self::money((int) $ap['outstanding_minor']),
                self::money((int) $ap['control_account_minor']),
                self::money($apDifference),
                $passed ? 'PASS' : 'FAIL'));
        }

        // Dated evidence is not a substitute for Accounting's true tie-out.
        // Show actionable, grounded source defects even when some unrelated
        // journal lines offset each other and a month happens to reconcile.
        $date = $anchor->toDateString();
        $unpostedInvoiceQuery = DB::table('invoices as i')
            ->whereNotNull('i.issued_at')
            ->whereDate('i.issued_at', '<=', $date)
            ->where('i.status', '!=', 'cancelled')
            ->whereNotExists(static function (Builder $query) use ($date): void {
                $query->selectRaw('1')->from('journal_entries as j')
                    ->whereColumn('j.source_id', 'i.id')
                    ->where('j.source_type', Invoice::class)
                    ->where('j.status', 'posted')
                    ->whereDate('j.entry_date', '<=', $date);
            });

        $unpostedPaymentQuery = DB::table('payments as p')
            ->whereNotNull('p.posted_at')
            ->whereDate('p.posted_at', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('p.reversed_at')->orWhereDate('p.reversed_at', '>', $date);
            })
            ->whereNotExists(static function (Builder $query) use ($date): void {
                $query->selectRaw('1')->from('journal_entries as j')
                    ->whereColumn('j.source_id', 'p.id')
                    ->where('j.source_type', Payment::class)
                    ->where('j.status', 'posted')
                    ->whereDate('j.entry_date', '<=', $date);
            });

        $invoiceCount = (clone $unpostedInvoiceQuery)->count();
        $paymentCount = (clone $unpostedPaymentQuery)->count();

        $this->line('Issued invoices without source-posted journal: '.$invoiceCount);
        foreach ((clone $unpostedInvoiceQuery)->orderBy('i.id')->limit(5)->get(['i.invoice_number', 'i.total_amount']) as $invoice) {
            $this->warn(sprintf('  Invoice %s (%s)', self::display($invoice->invoice_number), self::display($invoice->total_amount)));
        }
        $this->line('Posted payments without source-posted journal: '.$paymentCount);
        foreach ((clone $unpostedPaymentQuery)->orderBy('p.id')->limit(5)->get(['p.payment_number', 'p.amount']) as $payment) {
            $this->warn(sprintf('  Payment %s (%s)', self::display($payment->payment_number), self::display($payment->amount)));
        }

        $accountId = DB::table('sales_settings')->value('receivable_account_id')
            ?? DB::table('chart_accounts')->where('code', '1200')->value('id');
        $unattributed = 0;
        if (is_numeric($accountId)) {
            $unattributed = DB::table('journal_entry_lines as l')
                ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
                ->where('l.chart_account_id', (int) $accountId)
                ->whereNull('j.source_type')
                ->where('j.status', 'posted')
                ->whereDate('j.entry_date', '<=', $date)
                ->whereNotExists(static function (Builder $query) use ($date): void {
                    $query->selectRaw('1')->from('journal_entries as reversal')
                        ->where('reversal.source_type', JournalEntry::class)
                        ->whereColumn('reversal.source_id', 'j.id')
                        ->where('reversal.status', 'posted')
                        ->whereDate('reversal.entry_date', '<=', $date);
                })
                ->count();
        }
        $this->line('Posted AR control lines without source document: '.$unattributed);

        // Missing financial evidence remains a failed gate even when dated
        // differences cancel out. This command never performs repairs.
        if ($failures > 0 || $invoiceCount > 0 || $paymentCount > 0 || $unattributed > 0) {
            $this->error(sprintf('Finance audit FAILED: %d unreconciled months, %d unjournaled invoices, %d unjournaled payments, %d unlinked AR lines.',
                $failures, $invoiceCount, $paymentCount, $unattributed));

            return self::FAILURE;
        }

        $this->info('Finance audit PASSED.');

        return self::SUCCESS;
    }

    private static function display(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', ',');
    }
}
