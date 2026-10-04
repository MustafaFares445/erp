<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Services\Accounting\AccountingDocumentService;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\PeriodCloseChecklistService;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Company-level accounting that is not produced by sales or purchasing documents: opening
 * capital, month-end manual journals (one reversed, two left as drafts), the ten operating
 * expenses, and the period-close cycle (closed history, open September, persisted checks).
 *
 * Runs last, so the close checklist is evaluated against the complete month.
 */
final class DemoAccountingMonthSeeder extends DemoSeeder
{
    /**
     * date, merchant, description, total (VAT inclusive except bank fees), account, method, approved?, approve on, pay on, final state, optional supplier code (credit purchases from a demo supplier).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: float, 4: string, 5: string, 6: bool, 7: ?string, 8: ?string, 9: string, 10?: string}>
     */
    private const array Expenses = [
        ['2026-09-05', 'Gulf Stationery House', 'Office supplies', 480.0, '5900', 'Cash Desk', true, '2026-09-05', '2026-09-05', 'paid'],
        ['2026-09-07', 'Emirates Courier Services', 'Courier and local freight', 720.0, '5900', 'Operating Bank Transfer', true, '2026-09-07', '2026-09-08', 'paid'],
        ['2026-09-10', 'Precision Service Works', 'Equipment maintenance', 1250.0, '5900', 'Operating Bank Transfer', true, '2026-09-11', '2026-09-12', 'paid'],
        ['2026-09-13', 'Etisalat Business', 'Internet and communications', 650.0, '5400', 'Operating Bank Transfer', true, '2026-09-14', '2026-09-15', 'paid'],
        ['2026-09-16', 'Creative Print Studio', 'Marketing materials', 1100.0, '5900', 'Cheque Deposit', true, '2026-09-17', '2026-09-19', 'paid'],
        ['2026-09-19', 'MedSupply Gulf', 'Warehouse supplies on supplier credit', 840.0, '5900', 'Operating Bank Transfer', true, '2026-09-20', null, 'approved', 'DEMO-SUP-001'],
        ['2026-09-22', 'DEWA', 'Utility expense', 1450.0, '5400', 'Operating Bank Transfer', true, '2026-09-23', '2026-09-26', 'paid'],
        ['2026-09-25', 'Fleet Fuel Cards', 'Vehicle and transportation expense', 920.0, '5900', 'Operating Bank Transfer', true, '2026-09-26', '2026-09-27', 'paid'],
        ['2026-09-28', 'Emirates NBD', 'Bank fees', 275.0, '5900', 'Operating Bank Transfer', true, '2026-09-28', '2026-09-30', 'paid'],
        ['2026-10-02', 'Office Pantry Trading', 'General operating expense', 760.0, '5900', 'Cash Desk', false, null, null, 'draft'],
        ['2026-10-01', 'Emirates Courier Services', 'Duplicate courier invoice entered in error', 310.0, '5900', 'Operating Bank Transfer', false, null, null, 'cancelled'],
    ];

    protected function seed(DemoContext $context): void
    {
        if (JournalEntry::query()->where('description', 'like', '[DEMO]%')->exists()) {
            $this->note('Accounting month already present - skipped.');

            return;
        }

        $this->manualJournals($context);
        $this->expenses($context);
        $this->periodClose($context);
    }

    private function manualJournals(DemoContext $context): void
    {
        $journals = app(JournalPostingService::class);

        $context->at('2026-09-04 08:45');
        $accountant = $context->as('accountant');
        $journals->postNew($accountant, CarbonImmutable::parse('2026-09-04'), [
            $this->line('1110', '150000.00', '0.00', 'Opening bank balance'),
            $this->line('1100', '10000.00', '0.00', 'Opening petty cash'),
            $this->line('3100', '0.00', '160000.00', 'Share capital paid in'),
        ], '[DEMO] Opening capital contribution');

        $context->at('2026-09-20 15:00');
        $accountant = $context->as('accountant');
        $misposted = $journals->postNew($accountant, CarbonImmutable::parse('2026-09-20'), [
            $this->line('5400', '1100.00', '0.00', 'Marketing print run booked to utilities'),
            $this->line('1110', '0.00', '1100.00', 'Bank'),
        ], '[DEMO] Marketing materials posted to the wrong account');

        $context->at('2026-09-25 10:00');
        $chief = $context->as('chief_accountant');
        $journals->reverse($chief, $misposted->refresh(), CarbonImmutable::parse('2026-09-25'), 'Booked to utilities instead of marketing; re-entered below.');
        $context->at('2026-09-25 10:15');
        $context->as('accountant');

        $journals->postNew($context->actor('accountant'), CarbonImmutable::parse('2026-09-25'), [
            $this->line('5900', '1100.00', '0.00', 'Marketing print run'),
            $this->line('1110', '0.00', '1100.00', 'Bank'),
        ], '[DEMO] Marketing materials re-posted to other expenses');

        $context->at('2026-09-30 16:00');
        $accountant = $context->as('accountant');
        $journals->postNew($accountant, CarbonImmutable::parse('2026-09-30'), [
            $this->line('5400', '2300.00', '0.00', 'September utilities accrual'),
            $this->line('2200', '0.00', '2300.00', 'Accrued utilities'),
        ], '[DEMO] Month-end utilities accrual');

        $context->at('2026-09-30 17:00');
        $context->as('accountant');

        $journals->draft($accountant, CarbonImmutable::parse('2026-09-30'), [
            $this->line('5200', '18500.00', '0.00', 'September salaries'),
            $this->line('2200', '0.00', '18500.00', 'Accrued salaries'),
        ], '[DEMO] Draft: accrued salaries awaiting payroll sign-off');

        $context->at('2026-10-02 11:00');
        $context->as('accountant');

        $journals->draft($accountant, CarbonImmutable::parse('2026-10-02'), [
            $this->line('5500', '1250.00', '0.00', 'Monthly depreciation'),
            $this->line('1500', '0.00', '1250.00', 'Accumulated depreciation'),
        ], '[DEMO] Draft: monthly depreciation under review');
    }

    private function expenses(DemoContext $context): void
    {
        $documents = app(AccountingDocumentService::class);

        foreach (self::Expenses as $row) {
            [$date, $merchant, $description, $total, $accountCode, $method, $approve, $approveOn, $payOn, $final] = $row;
            $supplierCode = $row[10] ?? null;

            $taxable = $description !== 'Bank fees';
            $subtotal = $taxable ? round($total / 1.05, 2) : $total;
            $tax = round($total - $subtotal, 2);

            $context->at("{$date} 09:30");
            $accountant = $context->as('accountant');
            $expense = $documents->recordExpense($accountant, [
                'supplier_id' => $supplierCode === null ? null : DemoInventory::make()->supplier($supplierCode)->getKey(),
                'expense_account_id' => $this->account($accountCode)->getKey(),
                'payment_method_id' => $this->method($method)->getKey(),
                'expense_date' => $date,
                'due_date' => CarbonImmutable::parse($date)->addDays(14)->toDateString(),
                'merchant_name' => $merchant,
                'description' => "[DEMO] {$description}",
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'tax_total' => number_format($tax, 2, '.', ''),
                'total_amount' => number_format($total, 2, '.', ''),
                'requested_by' => $accountant->getKey(),
            ]);

            if ($final === 'cancelled') {
                $documents->cancelExpense($accountant, $expense);

                continue;
            }

            if (! $approve) {
                continue;
            }

            $context->at("{$approveOn} 14:00");
            $expense = $documents->approveExpense($context->as('chief_accountant'), $expense->refresh());

            if ($payOn !== null) {
                $context->at("{$payOn} 11:00");
                $documents->payExpense($context->as('accountant'), $expense->refresh(), CarbonImmutable::parse($payOn));
            }
        }
    }

    private function periodClose(DemoContext $context): void
    {
        $context->at('2026-10-03 16:00');
        $chief = $context->as('chief_accountant');
        $periods = app(FiscalPeriodService::class);

        FiscalPeriod::query()
            ->where('is_closed', false)
            ->whereDate('ends_at', '<', '2026-09-01')
            ->orderBy('starts_at')
            ->get()
            ->each(fn (FiscalPeriod $period) => $periods->close($chief, $period));

        $september = FiscalPeriod::query()->whereDate('starts_at', '2026-09-01')->firstOrFail();
        app(PeriodCloseChecklistService::class)->run($september, $chief);
    }

    /** @return array{chart_account_id: int, debit: string, credit: string, description: string} */
    private function line(string $code, string $debit, string $credit, string $description): array
    {
        return ['chart_account_id' => $this->account($code)->id, 'debit' => $debit, 'credit' => $credit, 'description' => $description];
    }

    private function account(string $code): ChartAccount
    {
        return ChartAccount::query()->where('code', $code)->first() ?? throw new LogicException("Account [{$code}] missing.");
    }

    private function method(string $name): PaymentMethod
    {
        return PaymentMethod::query()->where('name', $name)->first() ?? throw new LogicException("Payment method [{$name}] missing.");
    }
}
