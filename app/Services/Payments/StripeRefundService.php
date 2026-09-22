<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RefundStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Models\User;
use App\Services\Accounting\RefundService;
use App\Services\Payments\Providers\StripeClientInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Requests a Stripe refund against an already-approved ERP {@see Refund} —
 * the ERP refund remains the business authority; Stripe only executes it.
 * The refund is marked {@see RefundStatus::Paid} (via the existing
 * {@see RefundService::pay()}, which owns the accounting) only after Stripe
 * confirms success, never merely because a refund request was submitted.
 *
 * Idempotent: a refund that already carries a `provider_reference` is
 * returned unchanged rather than requested from Stripe a second time.
 */
final readonly class StripeRefundService
{
    public function __construct(
        private StripeClientInterface $client,
        private RefundService $refundService,
    ) {}

    public function refund(User $actor, Refund $refund, PaymentTransaction $originalTransaction, ?int $amountMinor = null): Refund
    {
        return DB::transaction(function () use ($actor, $refund, $originalTransaction, $amountMinor): Refund {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->sole();

            if ($locked->provider_reference !== null) {
                return $locked;
            }

            if ($locked->status !== RefundStatus::Approved) {
                throw new DomainException('Only an approved refund can be sent to Stripe.');
            }

            $method = $locked->paymentMethod;

            if (! $method instanceof PaymentMethod || $method->type !== PaymentMethodType::Stripe) {
                throw new DomainException('This refund is not assigned a Stripe payment method.');
            }

            if ((int) $originalTransaction->customer_id !== (int) $locked->customer_id) {
                throw new DomainException("The original transaction does not belong to this refund's customer.");
            }

            $paymentIntentId = $originalTransaction->payment_intent_id;

            if (! is_string($paymentIntentId) || $paymentIntentId === '') {
                throw new DomainException('The original transaction has no PaymentIntent to refund.');
            }

            if (! in_array($originalTransaction->status, [PaymentTransactionStatus::Succeeded, PaymentTransactionStatus::PartiallyRefunded], true)) {
                throw new DomainException('Only a successful provider transaction can be refunded.');
            }

            $providerRefund = $this->client->createRefund($paymentIntentId, $amountMinor);

            $locked->forceFill([
                'payment_transaction_id' => $originalTransaction->getKey(),
                'provider_reference' => $providerRefund->id,
                'provider_status' => $providerRefund->status,
            ])->save();

            if (! $providerRefund->isSucceeded()) {
                return $locked->refresh();
            }

            $originalTransaction->forceFill([
                'status' => $this->isFullyRefunded($originalTransaction, $amountMinor)
                    ? PaymentTransactionStatus::Refunded
                    : PaymentTransactionStatus::PartiallyRefunded,
                'refunded_at' => now(),
            ])->save();

            return $this->refundService->pay($actor, $locked->refresh());
        });
    }

    private function isFullyRefunded(PaymentTransaction $transaction, ?int $amountMinor): bool
    {
        return $amountMinor === null || $amountMinor >= $transaction->amount_minor;
    }
}
