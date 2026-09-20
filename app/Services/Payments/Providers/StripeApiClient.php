<?php

declare(strict_types=1);

namespace App\Services\Payments\Providers;

use Stripe\StripeClient;

/**
 * The real {@see StripeClientInterface} adapter, wrapping the official
 * `stripe/stripe-php` SDK. Never stores card data — Stripe Checkout collects
 * it on Stripe's own hosted page.
 */
final readonly class StripeApiClient implements StripeClientInterface
{
    public function __construct(private StripeClient $client) {}

    #[\Override]
    public function createCheckoutSession(array $params): StripeCheckoutSessionData
    {
        $session = $this->client->checkout->sessions->create(
            [
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => mb_strtolower($params['currency']),
                        'unit_amount' => $params['amount_minor'],
                        'product_data' => ['name' => 'IERP payment'],
                    ],
                    'quantity' => 1,
                ]],
                'client_reference_id' => $params['customer_reference'],
                'success_url' => $params['success_url'],
                'cancel_url' => $params['cancel_url'],
                'metadata' => $params['metadata'] ?? [],
            ],
            ['idempotency_key' => $params['idempotency_key']],
        );

        $paymentIntentId = $session->payment_intent;

        return new StripeCheckoutSessionData(
            id: $session->id,
            url: $session->url,
            paymentIntentId: is_string($paymentIntentId) ? $paymentIntentId : null,
            status: (string) $session->status,
        );
    }

    #[\Override]
    public function retrievePaymentIntent(string $paymentIntentId): StripePaymentIntentData
    {
        $intent = $this->client->paymentIntents->retrieve($paymentIntentId);

        $latestCharge = $intent->latest_charge;
        $lastError = $intent->last_payment_error?->toArray();
        $failureCode = $lastError['code'] ?? null;
        $failureMessage = $lastError['message'] ?? null;

        return new StripePaymentIntentData(
            id: $intent->id,
            status: $intent->status,
            amountMinor: $intent->amount,
            currency: mb_strtoupper($intent->currency),
            latestChargeId: is_string($latestCharge) ? $latestCharge : null,
            failureCode: is_string($failureCode) ? $failureCode : null,
            failureMessage: is_string($failureMessage) ? $failureMessage : null,
        );
    }

    #[\Override]
    public function createRefund(string $paymentIntentId, ?int $amountMinor = null): StripeRefundData
    {
        $refund = $amountMinor !== null
            ? $this->client->refunds->create(['payment_intent' => $paymentIntentId, 'amount' => $amountMinor])
            : $this->client->refunds->create(['payment_intent' => $paymentIntentId]);

        return new StripeRefundData(
            id: $refund->id,
            status: (string) $refund->status,
            amountMinor: $refund->amount,
        );
    }
}
