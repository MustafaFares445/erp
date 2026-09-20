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

it('reconciling twice does not move succeeded_at forward', function (): void {
    $transaction = PaymentTransaction::factory()->create(['payment_intent_id' => 'pi_test_999']);
    $this->fake->markSucceeded('pi_test_999');

    $first = app(StripePaymentReconciliationService::class)->reconcile($transaction);
    $firstSucceededAt = $first->succeeded_at;

    $second = app(StripePaymentReconciliationService::class)->reconcile($transaction->refresh());

    expect($second->succeeded_at->equalTo($firstSucceededAt))->toBeTrue();
});
