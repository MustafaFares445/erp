<?php

declare(strict_types=1);

namespace App\Services\Payments\Providers;

use Illuminate\Support\Str;

/**
 * A test double for {@see StripeClientInterface} that never touches the
 * network. Tests seed {@see self::$paymentIntents} to script what a
 * "verified provider status" looks like, and can inspect
 * {@see self::$createdSessions}/{@see self::$createdRefunds} to assert on
 * what the service under test requested.
 */
final class FakeStripeClient implements StripeClientInterface
{
    /** @var array<string, StripePaymentIntentData> */
    public array $paymentIntents = [];

    /** @var list<array<string, mixed>> */
    public array $createdSessions = [];

    /** @var list<array{payment_intent_id: string, amount_minor: int|null}> */
    public array $createdRefunds = [];

    #[\Override]
    public function createCheckoutSession(array $params): StripeCheckoutSessionData
    {
        $this->createdSessions[] = $params;

        $sessionId = 'cs_fake_'.Str::random(24);
        $paymentIntentId = 'pi_fake_'.Str::random(24);

        $this->paymentIntents[$paymentIntentId] = new StripePaymentIntentData(
            id: $paymentIntentId,
            status: 'requires_payment_method',
            amountMinor: $params['amount_minor'],
            currency: mb_strtoupper($params['currency']),
            latestChargeId: null,
        );

        return new StripeCheckoutSessionData(
            id: $sessionId,
            url: 'https://checkout.stripe.test/'.$sessionId,
            paymentIntentId: $paymentIntentId,
            status: 'open',
        );
    }

    #[\Override]
    public function retrievePaymentIntent(string $paymentIntentId): StripePaymentIntentData
    {
        return $this->paymentIntents[$paymentIntentId] ?? new StripePaymentIntentData(
            id: $paymentIntentId,
            status: 'requires_payment_method',
            amountMinor: 0,
            currency: 'AED',
            latestChargeId: null,
        );
    }

    #[\Override]
    public function createRefund(string $paymentIntentId, ?int $amountMinor = null): StripeRefundData
    {
        $this->createdRefunds[] = ['payment_intent_id' => $paymentIntentId, 'amount_minor' => $amountMinor];

        $intent = $this->paymentIntents[$paymentIntentId] ?? null;
        $fallbackAmount = $intent instanceof StripePaymentIntentData ? $intent->amountMinor : 0;

        return new StripeRefundData(
            id: 're_fake_'.Str::random(24),
            status: 'succeeded',
            amountMinor: $amountMinor ?? $fallbackAmount,
        );
    }

    /**
     * Test helper: mark a previously created checkout's PaymentIntent as
     * succeeded, as if Stripe had confirmed the payment.
     */
    public function markSucceeded(string $paymentIntentId): void
    {
        [$amountMinor, $currency] = $this->existingAmountAndCurrency($paymentIntentId);

        $this->paymentIntents[$paymentIntentId] = new StripePaymentIntentData(
            id: $paymentIntentId,
            status: 'succeeded',
            amountMinor: $amountMinor,
            currency: $currency,
            latestChargeId: 'ch_fake_'.Str::random(24),
        );
    }

    public function markFailed(string $paymentIntentId, string $failureCode, string $failureMessage): void
    {
        [$amountMinor, $currency] = $this->existingAmountAndCurrency($paymentIntentId);

        $this->paymentIntents[$paymentIntentId] = new StripePaymentIntentData(
            id: $paymentIntentId,
            status: 'requires_payment_method',
            amountMinor: $amountMinor,
            currency: $currency,
            latestChargeId: null,
            failureCode: $failureCode,
            failureMessage: $failureMessage,
        );
    }

    /** @return array{0: int, 1: string} */
    private function existingAmountAndCurrency(string $paymentIntentId): array
    {
        $existing = $this->paymentIntents[$paymentIntentId] ?? null;

        if (! $existing instanceof StripePaymentIntentData) {
            return [0, 'AED'];
        }

        return [$existing->amountMinor, $existing->currency];
    }
}
