<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Payments\CustomerDepositApplicationService;
use App\Services\Payments\PaymentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new ChartOfAccountsSeeder)->run();
    FiscalPeriod::factory()->create();

    $account = static fn (string $code): int => (int) ChartAccount::query()->where('code', $code)->sole()->getKey();

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

    $this->admin = User::factory()->admin()->create();
    $this->method = PaymentMethod::factory()->create(['chart_account_id' => $account('1100')]);
    $this->customer = CustomerProfile::factory()->create();
});

/** A fully-posted payment with no allocation of its own — the whole amount lands in Customer Deposits. */
function depositCoveragePayment(CustomerProfile $customer, PaymentMethod $method, User $actor, float $amount): Payment
{
    $draft = app(PaymentService::class)->createDraft($actor, [
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => $amount,
        'currency' => 'AED',
    ]);

    return app(PaymentService::class)->post($actor, $draft, []);
}

function depositCoverageInvoice(CustomerProfile $customer, float $total): Invoice
{
    return Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => number_format($total, 2, '.', ''),
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
    ]);
}

it('fully applies a single deposit that exactly covers the invoice', function (): void {
    depositCoveragePayment($this->customer, $this->method, $this->admin, 100.0);
    $invoice = depositCoverageInvoice($this->customer, 100.0);

    $result = app(CustomerDepositApplicationService::class)->applyEligibleDeposits($invoice);

    expect((float) $result->amount_paid)->toBe(100.0)
        ->and($result->outstandingAmount())->toBe(0.0)
        ->and($result->paymentAllocations)->toHaveCount(1);
});

it('applies only a partial deposit, leaving the remaining balance outstanding', function (): void {
    depositCoveragePayment($this->customer, $this->method, $this->admin, 40.0);
    $invoice = depositCoverageInvoice($this->customer, 100.0);

    $result = app(CustomerDepositApplicationService::class)->applyEligibleDeposits($invoice);

    expect((float) $result->amount_paid)->toBe(40.0)
        ->and($result->outstandingAmount())->toBe(60.0);
});

it('combines multiple deposits, oldest first, to settle one invoice', function (): void {
    depositCoveragePayment($this->customer, $this->method, $this->admin, 30.0);
    depositCoveragePayment($this->customer, $this->method, $this->admin, 90.0);
    $invoice = depositCoverageInvoice($this->customer, 100.0);

    $result = app(CustomerDepositApplicationService::class)->applyEligibleDeposits($invoice);

    expect((float) $result->amount_paid)->toBe(100.0)
        ->and($result->outstandingAmount())->toBe(0.0)
        ->and($result->paymentAllocations)->toHaveCount(2)
        // The second deposit only had 70 of its 90 consumed.
        ->and((float) $result->paymentAllocations->sum('amount'))->toBe(100.0);
});

it('spreads one large deposit across multiple invoices', function (): void {
    depositCoveragePayment($this->customer, $this->method, $this->admin, 150.0);
    $firstInvoice = depositCoverageInvoice($this->customer, 100.0);
    $secondInvoice = depositCoverageInvoice($this->customer, 100.0);

    $service = app(CustomerDepositApplicationService::class);
    $firstResult = $service->applyEligibleDeposits($firstInvoice);
    $secondResult = $service->applyEligibleDeposits($secondInvoice);

    expect((float) $firstResult->amount_paid)->toBe(100.0)
        ->and((float) $secondResult->amount_paid)->toBe(50.0)
        ->and($secondResult->outstandingAmount())->toBe(50.0);
});

it('posts Debit Customer Deposits / Credit Accounts Receivable for the applied amount', function (): void {
    depositCoveragePayment($this->customer, $this->method, $this->admin, 100.0);
    $invoice = depositCoverageInvoice($this->customer, 100.0);

    app(CustomerDepositApplicationService::class)->applyEligibleDeposits($invoice);

    $entry = JournalEntry::query()->where('source_type', Invoice::class)->where('source_id', $invoice->getKey())->sole();
    $lines = JournalEntryLine::query()->where('journal_entry_id', $entry->getKey())->get();

    $deposits = ChartAccount::query()->where('code', '2400')->sole();
    $receivable = ChartAccount::query()->where('code', '1200')->sole();

    expect((float) $lines->firstWhere('chart_account_id', $deposits->getKey())->debit)->toBe(100.0)
        ->and((float) $lines->firstWhere('chart_account_id', $receivable->getKey())->credit)->toBe(100.0);
});

it('is idempotent and never creates a duplicate allocation for the same payment and invoice', function (): void {
    depositCoveragePayment($this->customer, $this->method, $this->admin, 100.0);
    $invoice = depositCoverageInvoice($this->customer, 100.0);

    $service = app(CustomerDepositApplicationService::class);
    $service->applyEligibleDeposits($invoice);

    $second = $service->applyEligibleDeposits($invoice->refresh());

    expect($second->paymentAllocations)->toHaveCount(1)
        ->and((float) $second->amount_paid)->toBe(100.0);
});

it('never applies a deposit belonging to a different customer', function (): void {
    $otherCustomer = CustomerProfile::factory()->create();
    depositCoveragePayment($otherCustomer, $this->method, $this->admin, 100.0);
    $invoice = depositCoverageInvoice($this->customer, 100.0);

    $result = app(CustomerDepositApplicationService::class)->applyEligibleDeposits($invoice);

    expect((float) $result->amount_paid)->toBe(0.0)
        ->and($result->outstandingAmount())->toBe(100.0);
});

it('does nothing for a draft invoice or one with no outstanding balance', function (): void {
    depositCoveragePayment($this->customer, $this->method, $this->admin, 100.0);
    $draftInvoice = Invoice::factory()->create(['customer_id' => $this->customer->getKey(), 'status' => InvoiceStatus::Draft]);
    $settledInvoice = depositCoverageInvoice($this->customer, 50.0);
    $settledInvoice->forceFill(['amount_paid' => '50.00'])->save();

    $service = app(CustomerDepositApplicationService::class);

    expect($service->applyEligibleDeposits($draftInvoice)->paymentAllocations)->toHaveCount(0)
        ->and($service->applyEligibleDeposits($settledInvoice)->paymentAllocations)->toHaveCount(0);
});

it('stops scanning further deposits once the invoice becomes fully settled mid-loop', function (): void {
    depositCoveragePayment($this->customer, $this->method, $this->admin, 40.0);
    depositCoveragePayment($this->customer, $this->method, $this->admin, 40.0);
    depositCoveragePayment($this->customer, $this->method, $this->admin, 100.0);
    depositCoveragePayment($this->customer, $this->method, $this->admin, 100.0);
    $invoice = depositCoverageInvoice($this->customer, 100.0);

    $result = app(CustomerDepositApplicationService::class)->applyEligibleDeposits($invoice);

    expect((float) $result->amount_paid)->toBe(100.0)
        ->and($result->outstandingAmount())->toBe(0.0)
        // The fourth deposit was never touched: the loop broke as soon as the invoice settled.
        ->and($result->paymentAllocations)->toHaveCount(3);
});

it('skips a deposit that already carries an allocation for this invoice from a prior run', function (): void {
    $firstDeposit = depositCoveragePayment($this->customer, $this->method, $this->admin, 50.0);
    $invoice = depositCoverageInvoice($this->customer, 200.0);

    $service = app(CustomerDepositApplicationService::class);
    $firstRun = $service->applyEligibleDeposits($invoice);
    expect((float) $firstRun->amount_paid)->toBe(50.0)
        ->and($firstRun->outstandingAmount())->toBe(150.0);

    // A second deposit arrives later; re-running must skip the already-allocated first
    // deposit (hitting the early return) and apply only the new one.
    depositCoveragePayment($this->customer, $this->method, $this->admin, 100.0);
    $secondRun = $service->applyEligibleDeposits($invoice->refresh());

    expect((float) $secondRun->amount_paid)->toBe(150.0)
        ->and($secondRun->paymentAllocations)->toHaveCount(2)
        ->and($secondRun->paymentAllocations->firstWhere('payment_id', $firstDeposit->getKey()))
        ->not->toBeNull();
});

it('does not apply a deposit once the invoice has no outstanding balance left to consume', function (): void {
    $payment = depositCoveragePayment($this->customer, $this->method, $this->admin, 100.0);
    $settledInvoice = depositCoverageInvoice($this->customer, 50.0);
    $settledInvoice->forceFill(['amount_paid' => '50.00'])->save();

    $applyOneDeposit = new ReflectionMethod(CustomerDepositApplicationService::class, 'applyOneDeposit');
    $applyOneDeposit->invoke(app(CustomerDepositApplicationService::class), $this->admin, $payment, $settledInvoice);

    expect($settledInvoice->paymentAllocations()->count())->toBe(0);
});
