<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\PaymentTransaction;
use App\Models\TicketPaymentLink;
use App\Services\Payments\SystemActorResolver;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a verified Succeeded {@see PaymentTransaction} for a chargeable
 * support ticket into a settled {@see TicketPaymentLink}, exactly once —
 * the Stripe-driven counterpart to the existing manual dashboard settlement
 * action. Delegates the actual `ticket_payment_links`/`tickets` transition
 * to the existing {@see TicketPaymentService::settle()} instead of
 * duplicating its Pending -> Live / SLA-start rules, via the
 * narrowly-permissioned {@see SystemActorResolver} actor rather than a real
 * admin. The two paths are told apart only by the `source_channel` recorded
 * on the resulting activity log entry.
 */
final readonly class TicketProviderSettlementService
{
    public function __construct(
        private SystemActorResolver $systemActor,
        private TicketPaymentService $ticketPayments,
    ) {}

    public function settle(PaymentTransaction $transaction): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction): PaymentTransaction {
            /** @var PaymentTransaction $locked */
            $locked = PaymentTransaction::query()->whereKey($transaction->getKey())->lockForUpdate()->sole();

            $link = $locked->purpose;

            if (! $link instanceof TicketPaymentLink) {
                throw new DomainException('This payment transaction is not for a chargeable support ticket.');
            }

            if ($locked->isSettled()) {
                return $locked;
            }

            if (! $locked->status->isSettleable()) {
                throw new DomainException('Only a Succeeded provider transaction can be settled.');
            }

            $expectedAmountMinor = (int) round(((float) $link->amount) * 100);
            $ticketCustomerId = $link->ticket?->customer_id;

            if ((int) $locked->amount_minor !== $expectedAmountMinor
                || mb_strtoupper((string) $locked->currency) !== mb_strtoupper((string) $link->currency)
                || ! is_numeric($ticketCustomerId)
                || (int) $locked->customer_id !== (int) $ticketCustomerId) {
                throw new DomainException('The provider transaction does not match the ticket payment amount, currency, and customer.');
            }

            $key = $locked->getKey();
            $reference = $locked->payment_intent_id ?? $locked->checkout_session_id ?? (is_numeric($key) ? (string) $key : '');

            try {
                $this->ticketPayments->settle($link, $reference, $this->systemActor->resolve(), sourceChannel: 'stripe');
            } catch (InvalidStatusTransition) {
                // Already settled through a concurrent path (e.g. the dashboard) — idempotent no-op.
            }

            $settledPaymentId = $link->refresh()->payment_id;
            if (is_numeric($settledPaymentId)) {
                $locked->forceFill(['payment_id' => (int) $settledPaymentId])->save();
            }

            return $locked->refresh();
        });
    }
}
