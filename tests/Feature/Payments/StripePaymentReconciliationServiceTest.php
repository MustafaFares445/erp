<?php

declare(strict_types=1);

use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentTransaction;
use App\Services\Payments\Providers\FakeStripeClient;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Payments\Providers\StripePaymentIntentData;
use App\Services\Payments\StripePaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->fake = new FakeStripeClient;
    $this->app->instance(StripeClientInterface::class, $this->fake);
});

it('marks a transaction succeeded once Stripe confirms the PaymentIntent', function (): void {
    $transaction = PaymentTransaction::factory()->create(['payment_intent_id' => 'pi_test_123']);
    $this->fake->paymentIntents['pi_test_123'] = new StripePaymentIntentData(
        id: 'pi_test_123',
        status: 'succeeded',
        amountMinor: $transaction->amount_minor,
        currency: $transaction->currency,
        latestChargeId: 'ch_test_123',
    );

    $reconciled = app(StripePaymentReconciliationService::class)->reconcile($transaction);

    expect($reconciled->status)->toBe(PaymentTransactionStatus::Succeeded)
        ->and($reconciled->provider_charge_id)->toBe('ch_test_123')
        ->and($reconciled->succeeded_at)->not->toBeNull();
});

it('marks a transaction failed when Stripe reports a failure code', function (): void {
    $transaction = PaymentTransaction::factory()->create(['payment_intent_id' => 'pi_test_456']);
    $this->fake->markFailed('pi_test_456', 'card_declined', 'Your card was declined.');

    $reconciled = app(StripePaymentReconciliationService::class)->reconcile($transaction);

    expect($reconciled->status)->toBe(PaymentTransactionStatus::Failed)
        ->and($reconciled->failure_code)->toBe('card_declined');
});

it('leaves a transaction pending while it still requires action', function (): void {
    $transaction = PaymentTransaction::factory()->create(['payment_intent_id' => 'pi_test_789']);
    $this->fake->paymentIntents['pi_test_789'] = new StripePaymentIntentData(
        id: 'pi_test_789',
        status: 'requires_action',
        amountMinor: $transaction->amount_minor,
        currency: $transaction->currency,
        latestChargeId: null,
    );

    $reconciled = app(StripePaymentReconciliationService::class)->reconcile($transaction);

    expect($reconciled->status)->toBe(PaymentTransactionStatus::RequiresAction)
        ->and($reconciled->succeeded_at)->toBeNull();
});

it('marks a transaction cancelled when Stripe reports the PaymentIntent canceled', function (): void {
    $transaction = PaymentTransaction::factory()->create(['payment_intent_id' => 'pi_test_cancel']);
    $this->fake->paymentIntents['pi_test_cancel'] = new StripePaymentIntentData(
        id: 'pi_test_cancel',
        status: 'canceled',
        amountMinor: $transaction->amount_minor,
        currency: $transaction->currency,
        latestChargeId: null,
    );

    $reconciled = app(StripePaymentReconciliationService::class)->reconcile($transaction);

    expect($reconciled->status)->toBe(PaymentTransactionStatus::Cancelled)
        ->and($reconciled->cancelled_at)->not->toBeNull();
});

it('reconciling twice does not move succeeded_at forward', function (): void {
    $transaction = PaymentTransaction::factory()->create(['payment_intent_id' => 'pi_test_999']);
    $this->fake->paymentIntents['pi_test_999'] = new StripePaymentIntentData(
        id: 'pi_test_999',
        status: 'requires_payment_method',
        amountMinor: $transaction->amount_minor,
        currency: $transaction->currency,
        latestChargeId: null,
    );
    $this->fake->markSucceeded('pi_test_999');

    $first = app(StripePaymentReconciliationService::class)->reconcile($transaction);
    $firstSucceededAt = $first->succeeded_at;

    $second = app(StripePaymentReconciliationService::class)->reconcile($transaction->refresh());

    expect($second->succeeded_at->equalTo($firstSucceededAt))->toBeTrue();
});

it('refuses to mark a transaction succeeded when Stripe reports a different amount', function (): void {
    $transaction = PaymentTransaction::factory()->create([
        'payment_intent_id' => 'pi_test_amount',
        'amount_minor' => 10000,
        'currency' => 'AED',
    ]);
    $this->fake->paymentIntents['pi_test_amount'] = new StripePaymentIntentData(
        id: 'pi_test_amount',
        status: 'succeeded',
        amountMinor: 100,
        currency: 'AED',
        latestChargeId: 'ch_test_amount',
    );

    expect(fn () => app(StripePaymentReconciliationService::class)->reconcile($transaction))
        ->toThrow(DomainException::class, 'it was not marked succeeded');

    $transaction->refresh();

    expect($transaction->status)->not->toBe(PaymentTransactionStatus::Succeeded)
        ->and($transaction->succeeded_at)->toBeNull()
        ->and($transaction->provider_charge_id)->toBeNull();
});

it('refuses to mark a transaction succeeded when Stripe reports a different currency', function (): void {
    $transaction = PaymentTransaction::factory()->create([
        'payment_intent_id' => 'pi_test_currency',
        'amount_minor' => 10000,
        'currency' => 'AED',
    ]);
    $this->fake->paymentIntents['pi_test_currency'] = new StripePaymentIntentData(
        id: 'pi_test_currency',
        status: 'succeeded',
        amountMinor: 10000,
        currency: 'USD',
        latestChargeId: 'ch_test_currency',
    );

    expect(fn () => app(StripePaymentReconciliationService::class)->reconcile($transaction))
        ->toThrow(DomainException::class, 'it was not marked succeeded');

    expect($transaction->refresh()->status)->not->toBe(PaymentTransactionStatus::Succeeded);
});

it('matches the currency case-insensitively when the amount agrees', function (): void {
    $transaction = PaymentTransaction::factory()->create([
        'payment_intent_id' => 'pi_test_case',
        'amount_minor' => 10000,
        'currency' => 'aed',
    ]);
    $this->fake->paymentIntents['pi_test_case'] = new StripePaymentIntentData(
        id: 'pi_test_case',
        status: 'succeeded',
        amountMinor: 10000,
        currency: 'AED',
        latestChargeId: 'ch_test_case',
    );

    expect(app(StripePaymentReconciliationService::class)->reconcile($transaction)->status)
        ->toBe(PaymentTransactionStatus::Succeeded);
});
