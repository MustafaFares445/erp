<?php

declare(strict_types=1);

use App\Data\Accounting\WriteOffData;
use App\Enums\InvoiceStatus;
use App\Enums\WriteOffReason;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\ReceivableWriteOffService;
use App\Services\Payments\PaymentService;
use App\Services\Sales\InvoiceService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('keeps the trial balance balanced through issue, collection, and final receivable write off', function (): void {
    Gate::before(static fn (): bool => true);

    (new ChartOfAccountsSeeder)->run();
    FiscalPeriod::factory()->create();

    $account = fn (string $code): int => (int) ChartAccount::query()->where('code', $code)->sole()->getKey();

    SalesSetting::query()->create([
        'default_tax_percent' => '0.00',
        'default_quotation_validity_days' => 30,
        'receivable_account_id' => $account('1200'),
        'revenue_account_id' => $account('4100'),
        'deferred_tax_account_id' => $account('2350'),
        'tax_payable_account_id' => $account('2300'),
        'customer_deposits_account_id' => $account('2400'),
        'bad_debt_expense_account_id' => $account('6800'),
    ]);

    $customer = CustomerProfile::factory()->create();
    $recorder = User::factory()->create();
    $approver = User::factory()->create();

    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_date' => today(),
        'status' => InvoiceStatus::Draft,
        'issued_at' => null,
        'sent_at' => null,
        'subtotal' => '0.00',
        'tax_total' => '0.00',
        'total_amount' => '0.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
        'recognised_tax_amount' => '0.00',
    ]);
    $invoice->lines()->create([
        'description' => 'Taxable service',
        'quantity' => '1.000',
        'unit_price' => '100.00',
        'tax_amount' => '10.00',
        'line_total' => '110.00',
        'sort_order' => 1,
    ]);

    $invoice = app(InvoiceService::class)->issue($recorder, $invoice);
    $invoice->forceFill([
        'status' => InvoiceStatus::Sent,
        'sent_at' => now(),
    ])->save();

    $paymentMethod = PaymentMethod::factory()->create([
        'chart_account_id' => $account('1110'),
        'requires_proof' => false,
        'is_active' => true,
    ]);

    $payment = app(PaymentService::class)->createDraft($recorder, [
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $paymentMethod->getKey(),
        'amount' => '55.00',
        'currency' => 'USD',
        'payment_date' => today()->toDateString(),
    ]);

    app(PaymentService::class)->post($recorder, $payment, [[
        'invoice_id' => (int) $invoice->getKey(),
        'amount' => '55.00',
    ]]);

    $invoice->refresh();

    expect($invoice->outstandingMinor())->toBe(5_500)
        ->and($invoice->recognised_tax_amount)->toBe('5.00');

    $writeOff = app(ReceivableWriteOffService::class)->record(
        new WriteOffData(
            customerId: (int) $customer->getKey(),
            invoiceId: (int) $invoice->getKey(),
            amountMinor: 5_500,
            reasonCategory: WriteOffReason::CommerciallyUneconomic,
            reason: 'Remaining collection cost exceeds the expected recovery.',
        ),
        $recorder,
    );

    app(ReceivableWriteOffService::class)->approve($writeOff, $approver);

    $from = CarbonImmutable::today()->startOfMonth();
    $to = CarbonImmutable::today()->endOfMonth();
    $trial = app(FinancialReportService::class)->trialBalance($from, $to);
    $profitAndLoss = app(FinancialReportService::class)->profitAndLoss($from, $to);

    $row = static function (array $rows, string $code): array {
        $match = collect($rows)->firstWhere('code', $code);

        return is_array($match) ? $match : [];
    };

    $ar = $row($trial['rows'], '1200');
    $deferred = $row($trial['rows'], '2350');
    $taxPayable = $row($trial['rows'], '2300');
    $badDebt = $row($trial['rows'], '6800');
    $badDebtPnl = $row($profitAndLoss['sections']['expense']['rows'], '6800');

    expect($trial['foots'])->toBeTrue()
        ->and($trial['variance'])->toBe('0.00')
        ->and($trial['totalDebit'])->toBe($trial['totalCredit'])
        ->and($ar['closingBalance'] ?? null)->toBe('0.00')
        ->and($deferred['closingBalance'] ?? null)->toBe('0.00')
        ->and($taxPayable['closingBalance'] ?? null)->toBe('5.00')
        ->and($badDebt['periodDebit'] ?? null)->toBe('50.00')
        ->and($badDebtPnl['amount'] ?? null)->toBe('50.00')
        ->and($invoice->fresh()?->outstandingMinor())->toBe(0)
        ->and($invoice->fresh()?->status)->toBe(InvoiceStatus::WrittenOff);
});
