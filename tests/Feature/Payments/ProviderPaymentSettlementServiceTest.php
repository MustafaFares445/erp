<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\SalesSetting;
use App\Services\Payments\ProviderPaymentSettlementService;
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

    $this->stripeMethod = PaymentMethod::factory()->create([
        'type' => PaymentMethodType::Stripe,
        'chart_account_id' => $account('1100'),
        'is_active' => true,
        'requires_proof' => false,
    ]);
    $this->customer = CustomerProfile::factory()->create();
});

it('settles a succeeded order-purpose transaction into exactly one Payment as an unallocated deposit', function (): void {
    $order = Order::factory()->for($this->customer, 'customer')->create(['grand_total' => '200.00']);
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $this->customer->getKey(),
        'purpose_type' => Order::class,
        'purpose_id' => $order->getKey(),
        'amount_minor' => 20000,
        'currency' => 'AED',
        'payment_intent_id' => 'pi_settle_order',
    ]);

    $settled = app(ProviderPaymentSettlementService::class)->settle($transaction);

    expect($settled->payment_id)->not->toBeNull();

    $payment = Payment::query()->findOrFail($settled->payment_id);

    expect((float) $payment->amount)->toBe(200.0)
        ->and($payment->allocations)->toHaveCount(0)
        ->and(Payment::query()->count())->toBe(1);
});

it('settles a succeeded invoice-purpose transaction and allocates directly to that invoice', function (): void {
    $invoice = Invoice::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => '75.00',
        'amount_paid' => '0.00',
    ]);
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $this->customer->getKey(),
        'purpose_type' => Invoice::class,
        'purpose_id' => $invoice->getKey(),
        'amount_minor' => 7500,
        'currency' => 'AED',
        'payment_intent_id' => 'pi_settle_invoice',
    ]);

    app(ProviderPaymentSettlementService::class)->settle($transaction);

    expect((float) $invoice->refresh()->amount_paid)->toBe(75.0)
        ->and($invoice->outstandingAmount())->toBe(0.0);
});

it('is idempotent and never creates a second Payment for an already-settled transaction', function (): void {
    $order = Order::factory()->for($this->customer, 'customer')->create(['grand_total' => '100.00']);
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $this->customer->getKey(),
        'purpose_type' => Order::class,
        'purpose_id' => $order->getKey(),
        'amount_minor' => 10000,
        'currency' => 'AED',
        'payment_intent_id' => 'pi_settle_idempotent',
    ]);

    $service = app(ProviderPaymentSettlementService::class);
    $first = $service->settle($transaction);
    $second = $service->settle($first->refresh());

    expect($first->payment_id)->toBe($second->payment_id)
        ->and(Payment::query()->count())->toBe(1);
});

it('refuses to settle a transaction that has not succeeded', function (): void {
    $order = Order::factory()->for($this->customer, 'customer')->create(['grand_total' => '100.00']);
    $transaction = PaymentTransaction::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'purpose_type' => Order::class,
        'purpose_id' => $order->getKey(),
        'status' => PaymentTransactionStatus::Pending,
    ]);

    expect(fn () => app(ProviderPaymentSettlementService::class)->settle($transaction))
        ->toThrow(DomainException::class);

    expect(Payment::query()->count())->toBe(0);
});

it('refuses to settle when no active Stripe payment method is configured', function (): void {
    $this->stripeMethod->update(['is_active' => false]);

    $order = Order::factory()->for($this->customer, 'customer')->create(['grand_total' => '100.00']);
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $this->customer->getKey(),
        'purpose_type' => Order::class,
        'purpose_id' => $order->getKey(),
        'payment_intent_id' => 'pi_no_method',
    ]);

    expect(fn () => app(ProviderPaymentSettlementService::class)->settle($transaction))
        ->toThrow(DomainException::class);
});
