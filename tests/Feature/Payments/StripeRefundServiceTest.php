<?php

declare(strict_types=1);

use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RefundStatus;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Payments\Providers\FakeStripeClient;
use App\Services\Payments\Providers\StripeCheckoutSessionData;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Payments\Providers\StripePaymentIntentData;
use App\Services\Payments\Providers\StripeRefundData;
use App\Services\Payments\StripeRefundService;
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
    $this->customer = CustomerProfile::factory()->create();
    $this->stripeMethod = PaymentMethod::factory()->create([
        'type' => PaymentMethodType::Stripe,
        'chart_account_id' => $account('1100'),
        'is_active' => true,
    ]);
    $this->fake = new FakeStripeClient;
    $this->app->instance(StripeClientInterface::class, $this->fake);
});

function refundCoverageTransaction(CustomerProfile $customer, float $amount = 100.0): PaymentTransaction
{
    return PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $customer->getKey(),
        'amount_minor' => (int) round($amount * 100),
        'currency' => 'AED',
        'payment_intent_id' => 'pi_refund_'.fake()->unique()->numerify('######'),
    ]);
}

function refundCoverageRefund(CustomerProfile $customer, PaymentMethod $method, float $amount = 100.0): Refund
{
    return Refund::factory()->create([
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'credit_note_id' => null,
        'invoice_id' => null,
        'amount' => number_format($amount, 2, '.', ''),
        'status' => RefundStatus::Approved,
    ]);
}

it('marks the ERP refund Paid only after Stripe confirms the refund', function (): void {
    $transaction = refundCoverageTransaction($this->customer);
    $refund = refundCoverageRefund($this->customer, $this->stripeMethod);

    $result = app(StripeRefundService::class)->refund($this->admin, $refund, $transaction);

    expect($result->status)->toBe(RefundStatus::Paid)
        ->and($result->provider_reference)->not->toBeNull()
        ->and($transaction->refresh()->status)->toBe(PaymentTransactionStatus::Refunded)
        ->and($transaction->refunded_at)->not->toBeNull();
});

it('is idempotent and never requests a second Stripe refund for the same ERP refund', function (): void {
    $transaction = refundCoverageTransaction($this->customer);
    $refund = refundCoverageRefund($this->customer, $this->stripeMethod);

    $service = app(StripeRefundService::class);
    $first = $service->refund($this->admin, $refund, $transaction);
    $second = $service->refund($this->admin, $first->refresh(), $transaction->refresh());

    expect($second->provider_reference)->toBe($first->provider_reference)
        ->and($this->fake->createdRefunds)->toHaveCount(1);
});

it('refuses a refund that is not yet approved', function (): void {
    $transaction = refundCoverageTransaction($this->customer);
    $refund = Refund::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'payment_method_id' => $this->stripeMethod->getKey(),
        'status' => RefundStatus::Draft,
    ]);

    expect(fn () => app(StripeRefundService::class)->refund($this->admin, $refund, $transaction))
        ->toThrow(DomainException::class);
});

it('refuses a refund whose payment method is not Stripe', function (): void {
    $transaction = refundCoverageTransaction($this->customer);
    $manualMethod = PaymentMethod::factory()->create(['type' => PaymentMethodType::BankTransfer]);
    $refund = refundCoverageRefund($this->customer, $manualMethod);

    expect(fn () => app(StripeRefundService::class)->refund($this->admin, $refund, $transaction))
        ->toThrow(DomainException::class);
});

it('refuses a transaction that does not belong to the refund customer', function (): void {
    $otherCustomer = CustomerProfile::factory()->create();
    $transaction = refundCoverageTransaction($otherCustomer);
    $refund = refundCoverageRefund($this->customer, $this->stripeMethod);

    expect(fn () => app(StripeRefundService::class)->refund($this->admin, $refund, $transaction))
        ->toThrow(DomainException::class);
});

it('refuses to refund a transaction with no PaymentIntent', function (): void {
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $this->customer->getKey(),
        'payment_intent_id' => null,
    ]);
    $refund = refundCoverageRefund($this->customer, $this->stripeMethod);

    expect(fn () => app(StripeRefundService::class)->refund($this->admin, $refund, $transaction))
        ->toThrow(DomainException::class, 'has no PaymentIntent to refund');
});

it('refuses to refund a transaction that never succeeded with the provider', function (): void {
    $transaction = PaymentTransaction::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'status' => PaymentTransactionStatus::Pending,
        'payment_intent_id' => 'pi_refund_pending',
    ]);
    $refund = refundCoverageRefund($this->customer, $this->stripeMethod);

    expect(fn () => app(StripeRefundService::class)->refund($this->admin, $refund, $transaction))
        ->toThrow(DomainException::class, 'Only a successful provider transaction can be refunded.');
});

it('records the provider reference but does not settle the ERP refund while Stripe leaves it pending', function (): void {
    $pendingRefundClient = new class implements StripeClientInterface
    {
        public function createCheckoutSession(array $params): StripeCheckoutSessionData
        {
            throw new LogicException('Not used by this refund test.');
        }

        public function retrievePaymentIntent(string $paymentIntentId): StripePaymentIntentData
        {
            throw new LogicException('Not used by this refund test.');
        }

        public function createRefund(string $paymentIntentId, ?int $amountMinor = null, ?string $idempotencyKey = null): StripeRefundData
        {
            return new StripeRefundData(id: 're_fake_pending', status: 'pending', amountMinor: $amountMinor ?? 0);
        }
    };
    $this->app->instance(StripeClientInterface::class, $pendingRefundClient);

    $transaction = refundCoverageTransaction($this->customer);
    $refund = refundCoverageRefund($this->customer, $this->stripeMethod);

    $result = app(StripeRefundService::class)->refund($this->admin, $refund, $transaction);

    expect($result->status)->toBe(RefundStatus::Approved)
        ->and($result->provider_reference)->toBe('re_fake_pending')
        ->and($transaction->refresh()->status)->toBe(PaymentTransactionStatus::Succeeded);
});

it('supports a partial refund amount', function (): void {
    $transaction = refundCoverageTransaction($this->customer, 100.0);
    $refund = refundCoverageRefund($this->customer, $this->stripeMethod, 40.0);

    $result = app(StripeRefundService::class)->refund($this->admin, $refund, $transaction, 4000);

    expect($result->status)->toBe(RefundStatus::Paid)
        ->and($transaction->refresh()->status)->toBe(PaymentTransactionStatus::PartiallyRefunded);
});

it('refuses a Stripe amount that differs from the approved ERP refund amount', function (): void {
    $transaction = refundCoverageTransaction($this->customer, 100.0);
    $refund = refundCoverageRefund($this->customer, $this->stripeMethod, 40.0);

    expect(fn () => app(StripeRefundService::class)->refund($this->admin, $refund, $transaction, 9000))
        ->toThrow(DomainException::class, 'must equal the approved refund amount')
        ->and($this->fake->createdRefunds)->toBe([])
        ->and($refund->refresh()->status)->toBe(RefundStatus::Approved);
});

it('defaults the Stripe refund amount to the ERP refund amount and sends a stable idempotency key', function (): void {
    $transaction = refundCoverageTransaction($this->customer, 100.0);
    $refund = refundCoverageRefund($this->customer, $this->stripeMethod, 40.0);

    app(StripeRefundService::class)->refund($this->admin, $refund, $transaction);

    expect($this->fake->createdRefunds)->toHaveCount(1)
        ->and($this->fake->createdRefunds[0]['amount_minor'])->toBe(4000)
        ->and($this->fake->createdRefunds[0]['idempotency_key'])->toBe('refund-'.$refund->getKey())
        ->and($transaction->refresh()->status)->toBe(PaymentTransactionStatus::PartiallyRefunded);
});

it('refuses a refund above what remains refundable after earlier provider refunds', function (): void {
    $transaction = refundCoverageTransaction($this->customer, 100.0);
    $service = app(StripeRefundService::class);

    $service->refund($this->admin, refundCoverageRefund($this->customer, $this->stripeMethod, 70.0), $transaction);

    $second = refundCoverageRefund($this->customer, $this->stripeMethod, 40.0);

    expect(fn () => $service->refund($this->admin, $second, $transaction->refresh()))
        ->toThrow(DomainException::class, 'exceeds what remains refundable')
        ->and($this->fake->createdRefunds)->toHaveCount(1)
        ->and($second->refresh()->provider_reference)->toBeNull()
        ->and($second->status)->toBe(RefundStatus::Approved);
});

it('refuses a non-positive refund amount', function (): void {
    $transaction = refundCoverageTransaction($this->customer, 100.0);
    $refund = refundCoverageRefund($this->customer, $this->stripeMethod, 40.0);

    expect(fn () => app(StripeRefundService::class)->refund($this->admin, $refund, $transaction, 0))
        ->toThrow(DomainException::class, 'exceeds what remains refundable')
        ->and($this->fake->createdRefunds)->toBe([]);
});

it('marks the original transaction fully refunded once successive refunds cover it', function (): void {
    $transaction = refundCoverageTransaction($this->customer, 100.0);
    $service = app(StripeRefundService::class);

    $service->refund($this->admin, refundCoverageRefund($this->customer, $this->stripeMethod, 40.0), $transaction);
    expect($transaction->refresh()->status)->toBe(PaymentTransactionStatus::PartiallyRefunded);

    $service->refund($this->admin, refundCoverageRefund($this->customer, $this->stripeMethod, 60.0), $transaction);

    expect($transaction->refresh()->status)->toBe(PaymentTransactionStatus::Refunded);
});

it('keeps the provider reference when ERP payout posting fails and finishes it on retry without a second Stripe call', function (): void {
    $transaction = refundCoverageTransaction($this->customer, 100.0);
    $refund = refundCoverageRefund($this->customer, $this->stripeMethod, 100.0);
    $service = app(StripeRefundService::class);

    $this->stripeMethod->forceFill(['is_active' => false])->save();

    expect(fn () => $service->refund($this->admin, $refund, $transaction))
        ->toThrow(DomainException::class);

    $refund->refresh();

    expect($refund->provider_reference)->not->toBeNull()
        ->and($refund->status)->toBe(RefundStatus::Approved)
        ->and($transaction->refresh()->status)->toBe(PaymentTransactionStatus::Succeeded);

    $this->stripeMethod->forceFill(['is_active' => true])->save();

    $result = $service->refund($this->admin, $refund, $transaction);

    expect($result->status)->toBe(RefundStatus::Paid)
        ->and($result->provider_reference)->toBe($refund->provider_reference)
        ->and($this->fake->createdRefunds)->toHaveCount(1)
        ->and($transaction->refresh()->status)->toBe(PaymentTransactionStatus::Refunded);
});
