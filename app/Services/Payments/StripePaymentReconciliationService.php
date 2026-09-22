<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentTransactionStatus;
use App\Events\PaymentTransactionFailed;
use App\Events\PaymentTransactionSucceeded;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Payments\Providers\StripePaymentIntentData;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes a {@see PaymentTransaction} from Stripe's own view of its
 * PaymentIntent. This never settles the transaction into an ERP
 * {@see Payment} — that is
 * {@see ProviderPaymentSettlementService}'s job, kept separate so "did
 * Stripe confirm this" and "did the ERP recognise this" stay two distinct
 * questions, per the payment/invoice architecture note.
 */
final readonly class StripePaymentReconciliationService
{
    public function __construct(private StripeClientInterface $client) {}

    public function reconcile(PaymentTransaction $transaction): PaymentTransaction
    {
        $paymentIntentId = $transaction->payment_intent_id;

        if (! is_string($paymentIntentId) || $paymentIntentId === '') {
            throw new DomainException('This transaction has no PaymentIntent to reconcile against.');
        }

        $intent = $this->client->retrievePaymentIntent($paymentIntentId);

        return DB::transaction(function () use ($transaction, $intent): PaymentTransaction {
            /** @var PaymentTransaction $locked */
            $locked = PaymentTransaction::query()->whereKey($transaction->getKey())->lockForUpdate()->sole();

            $previousStatus = $locked->status;
            $status = $this->mapStatus($intent);

            $locked->forceFill([
                'status' => $status,
                'provider_charge_id' => $intent->latestChargeId ?? $locked->provider_charge_id,
                'last_provider_event_id' => $intent->lastEventId ?? $locked->last_provider_event_id,
                'failure_code' => $intent->failureCode,
                'failure_message' => $intent->failureMessage,
                'succeeded_at' => $status === PaymentTransactionStatus::Succeeded ? ($locked->succeeded_at ?? now()) : $locked->succeeded_at,
                'cancelled_at' => $status === PaymentTransactionStatus::Cancelled ? ($locked->cancelled_at ?? now()) : $locked->cancelled_at,
            ])->save();

            $refreshed = $locked->refresh();

            if ($status !== $previousStatus && $status === PaymentTransactionStatus::Succeeded) {
                PaymentTransactionSucceeded::dispatch($refreshed);
            } elseif ($status !== $previousStatus && $status === PaymentTransactionStatus::Failed) {
                PaymentTransactionFailed::dispatch($refreshed);
            }

            return $refreshed;
        });
    }

    private function mapStatus(StripePaymentIntentData $intent): PaymentTransactionStatus
    {
        return match ($intent->status) {
            'succeeded' => PaymentTransactionStatus::Succeeded,
            'canceled' => PaymentTransactionStatus::Cancelled,
            'requires_action', 'requires_confirmation' => PaymentTransactionStatus::RequiresAction,
            'requires_payment_method' => $intent->failureCode !== null
                ? PaymentTransactionStatus::Failed
                : PaymentTransactionStatus::Pending,
            default => PaymentTransactionStatus::Pending,
        };
    }
}
