<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RefundStatus;
use App\Models\JournalEntryLine;
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
 * Idempotent: a refund that already carries a `provider_reference` is never
 * requested from Stripe a second time (and the request carries a stable
 * idempotency key regardless). The provider reference is committed before the
 * ERP payout is posted, so a posting failure can be retried without losing it.
 */
final readonly class StripeRefundService
{
    public function __construct(
        private StripeClientInterface $client,
        private RefundService $refundService,
    ) {}

    /**
     * @param  int|null  $amountMinor  defaults to the ERP refund amount in minor units
     */
    public function refund(User $actor, Refund $refund, PaymentTransaction $originalTransaction, ?int $amountMinor = null): Refund
    {
        /** @var array{refund: Refund, amount: int} $prepared */
        $prepared = DB::transaction(fn (): array => $this->prepare($refund, $originalTransaction, $amountMinor));

        $locked = $prepared['refund'];

        if ($locked->provider_reference === null) {
            // The provider call runs outside any transaction: once Stripe has
            // accepted the refund its reference must survive even when the ERP
            // payout posting fails afterwards. The idempotency key makes a
            // retry return the same Stripe refund instead of creating another.
            $providerRefund = $this->client->createRefund(
                (string) $originalTransaction->payment_intent_id,
                $prepared['amount'],
                'refund-'.$locked->id,
            );

            $locked = DB::transaction(function () use ($locked, $originalTransaction, $providerRefund): Refund {
                /** @var Refund $fresh */
                $fresh = Refund::query()->whereKey($locked->getKey())->lockForUpdate()->sole();

                $fresh->forceFill([
                    'payment_transaction_id' => $originalTransaction->getKey(),
                    'provider_reference' => $providerRefund->id,
                    'provider_status' => $providerRefund->status,
                ])->save();

                return $fresh->refresh();
            });
        }

        return $this->settle($actor, $locked, $originalTransaction, $prepared['amount']);
    }

    /**
     * @return array{refund: Refund, amount: int}
     */
    private function prepare(Refund $refund, PaymentTransaction $originalTransaction, ?int $amountMinor): array
    {
        /** @var Refund $locked */
        $locked = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->sole();

        $requestMinor = $amountMinor ?? JournalEntryLine::toMinorUnits($locked->amount);

        if ($locked->provider_reference !== null) {
            return ['refund' => $locked, 'amount' => $requestMinor];
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

        PaymentTransaction::query()->whereKey($originalTransaction->getKey())->lockForUpdate()->sole();

        $refundableMinor = (int) $originalTransaction->amount_minor
            - $this->priorProviderRefundMinor($originalTransaction, $locked);

        if ($requestMinor <= 0 || $requestMinor > $refundableMinor) {
            throw new DomainException('The refund amount exceeds what remains refundable on the original transaction.');
        }

        return ['refund' => $locked, 'amount' => $requestMinor];
    }

    private function settle(User $actor, Refund $refund, PaymentTransaction $originalTransaction, int $amountMinor): Refund
    {
        if ($refund->status !== RefundStatus::Approved || $refund->provider_status !== 'succeeded') {
            return $refund;
        }

        return DB::transaction(function () use ($actor, $refund, $originalTransaction, $amountMinor): Refund {
            $transaction = PaymentTransaction::query()
                ->whereKey($originalTransaction->getKey())
                ->lockForUpdate()
                ->sole();

            $refundedMinor = $this->priorProviderRefundMinor($transaction, $refund) + $amountMinor;

            $transaction->forceFill([
                'status' => $refundedMinor >= (int) $transaction->amount_minor
                    ? PaymentTransactionStatus::Refunded
                    : PaymentTransactionStatus::PartiallyRefunded,
                'refunded_at' => now(),
            ])->save();

            return $this->refundService->pay($actor, $refund->refresh());
        });
    }

    /**
     * Money Stripe has already been asked to return for this transaction by
     * other ERP refunds, whether still pending or already settled.
     */
    private function priorProviderRefundMinor(PaymentTransaction $transaction, Refund $current): int
    {
        return Refund::query()
            ->where('payment_transaction_id', $transaction->getKey())
            ->whereNotNull('provider_reference')
            ->whereKeyNot($current->getKey())
            ->get(['amount'])
            ->sum(fn (Refund $prior): int => JournalEntryLine::toMinorUnits($prior->amount));
    }
}
