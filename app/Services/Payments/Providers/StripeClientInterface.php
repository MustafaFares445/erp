<?php

declare(strict_types=1);

namespace App\Services\Payments\Providers;

use App\Services\Payments\StripeCheckoutService;
use App\Services\Payments\StripePaymentReconciliationService;
use App\Services\Payments\StripeRefundService;

/**
 * The only boundary between this codebase's payment services and the
 * Stripe SDK. No HTTP controller/webhook route exists yet — this interface
 * exists so {@see StripeCheckoutService},
 * {@see StripePaymentReconciliationService}, and
 * {@see StripeRefundService} are already shaped for
 * a future webhook/HTTP adapter to call, and so tests can substitute
 * {@see FakeStripeClient} instead of reaching the real network.
 */
interface StripeClientInterface
{
    /**
     * @param  array{amount_minor: int, currency: string, customer_reference: string, success_url: string, cancel_url: string, idempotency_key: string, metadata?: array<string, string>}  $params
     */
    public function createCheckoutSession(array $params): StripeCheckoutSessionData;

    public function retrievePaymentIntent(string $paymentIntentId): StripePaymentIntentData;

    public function createRefund(string $paymentIntentId, ?int $amountMinor = null): StripeRefundData;
}
