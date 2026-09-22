<?php

declare(strict_types=1);

use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\DepositApplicationIssue;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Sales\InvoiceService;
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

it('automatically applies an existing customer deposit right after issuance', function (): void {
    $draft = app(PaymentService::class)->createDraft($this->admin, [
        'customer_id' => $this->customer->getKey(),
        'payment_method_id' => $this->method->getKey(),
        'amount' => 100.0,
        'currency' => 'AED',
    ]);
    app(PaymentService::class)->post($this->admin, $draft, []);

    $invoice = app(InvoiceService::class)->createStandalone(
        $this->admin,
        ['customer_id' => $this->customer->getKey()],
        [['quantity' => 1, 'unit_price' => 100, 'tax_amount' => 0]],
    );

    $issued = app(InvoiceService::class)->issue($this->admin, $invoice);

    expect((float) $issued->refresh()->amount_paid)->toBe(100.0)
        ->and($issued->outstandingAmount())->toBe(0.0)
        ->and(DepositApplicationIssue::query()->count())->toBe(0);
});

it('leaves the issued invoice standing even if deposit application fails, and records the issue', function (): void {
    $invoice = app(InvoiceService::class)->createStandalone(
        $this->admin,
        ['customer_id' => $this->customer->getKey()],
        [['quantity' => 1, 'unit_price' => 100, 'tax_amount' => 0]],
    );

    // Remove the Customer Deposits account after the invoice is drafted so
    // the automatic post-issuance application step fails deterministically.
    SalesSetting::current()->update(['customer_deposits_account_id' => null]);

    $draft = app(PaymentService::class)->createDraft($this->admin, [
        'customer_id' => $this->customer->getKey(),
        'payment_method_id' => $this->method->getKey(),
        'amount' => 100.0,
        'currency' => 'AED',
    ]);
    SalesSetting::current()->update([
        'customer_deposits_account_id' => (int) ChartAccount::query()->where('code', '2400')->sole()->getKey(),
    ]);
    app(PaymentService::class)->post($this->admin, $draft, []);
    SalesSetting::current()->update(['customer_deposits_account_id' => null]);

    $issued = app(InvoiceService::class)->issue($this->admin, $invoice);

    expect($issued->isIssued())->toBeTrue()
        ->and((float) $issued->refresh()->amount_paid)->toBe(0.0)
        ->and(DepositApplicationIssue::query()->where('invoice_id', $issued->getKey())->whereNull('resolved_at')->exists())->toBeTrue();
});
