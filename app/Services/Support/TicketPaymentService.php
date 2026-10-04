<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentMethodType;
use App\Enums\TicketStatus;
use App\Models\PaymentMethod;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Payments\SystemActorResolver;
use App\Services\Settings\CurrencyCatalogService;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Chargeable-ticket payment holds and settlement (FR-040–048,
 * contracts/ticket-lifecycle.md §5). Settlement records the collected money
 * through the canonical Payment service as an unallocated customer deposit,
 * then transitions the ticket to Live. Any later service invoice consumes
 * that deposit through the existing allocation/tax/accounting workflow.
 */
final readonly class TicketPaymentService
{
    public function __construct(
        private SlaService $slaService,
        private CurrencyCatalogService $currencies,
        private PaymentService $payments,
        private SystemActorResolver $systemActor,
    ) {}

    /**
     * Creates the pending payment link for a newly chargeable ticket, inside
     * {@see TicketIntakeService::create()}'s own transaction. Not a public
     * mutating entry point in its own right, so it does not self-check
     * authorization — the caller already authorized ticket creation.
     */
    public function createForTicket(Ticket $ticket, float $amount, string $currency): TicketPaymentLink
    {
        return TicketPaymentLink::query()->create([
            'ticket_id' => $ticket->getKey(),
            'amount' => $amount,
            'currency' => $this->currencies->normalizeBase($currency, 'currency'),
            'status' => PaymentLinkStatus::Pending,
        ]);
    }

    /**
     * The only write path to `PaymentLinkStatus::Settled` (contracts/
     * ticket-lifecycle.md §5). Rejects an already-settled link (FR-044,
     * idempotent) and a ticket cancelled between page-load and submit
     * (Edge Cases), each rejection logged in its own right (FR-048).
     *
     * The pending-status check and the settlement write happen against rows
     * locked with `lockForUpdate()` inside the same transaction, so two
     * concurrent settlement attempts on the same link serialize instead of
     * both applying (FR-044, SC-003) — mirroring the `lockForUpdate()`
     * pattern already used by {@see TicketIntakeService::nextTicketNumber()}.
     *
     * `$sourceChannel` only labels the activity log entry — 'dashboard' for
     * a manually-entered reference (the default, used by the Filament
     * action), 'stripe' when {@see TicketProviderSettlementService}
     * calls this with a verified Stripe transaction reference instead. The
     * transition rules themselves never differ between the two callers.
     */
    public function settle(
        TicketPaymentLink $link,
        string $methodReference,
        User $actor,
        ?int $paymentMethodId = null,
        string $sourceChannel = 'dashboard',
    ): void {
        $ticket = $link->ticket;

        /** @var Ticket $ticket */
        Gate::forUser($actor)->authorize('settlePayment', $ticket);

        try {
            DB::transaction(function () use ($link, $actor, $methodReference, $paymentMethodId, $sourceChannel): void {
                $lockedLink = TicketPaymentLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();
                $lockedTicket = Ticket::query()->whereKey($lockedLink->ticket_id)->lockForUpdate()->firstOrFail();

                if ($lockedLink->status !== PaymentLinkStatus::Pending || $lockedTicket->status !== TicketStatus::PendingPayment) {
                    throw InvalidStatusTransition::fromTo($lockedLink->status->value, PaymentLinkStatus::Settled->value);
                }

                $financialActor = $this->systemActor->resolve();
                $resolvedPaymentMethodId = $this->resolvePaymentMethodId($paymentMethodId, $sourceChannel);
                $payment = $this->payments->createDraft($financialActor, [
                    'customer_id' => $lockedTicket->customer_id,
                    'payment_method_id' => $resolvedPaymentMethodId,
                    'amount' => (float) $lockedLink->amount,
                    'currency' => $lockedLink->currency,
                    'payment_date' => now()->toDateString(),
                    'external_reference' => $methodReference,
                    'notes' => "Support ticket {$lockedTicket->ticket_number} prepayment",
                ]);
                $payment->forceFill(['source' => $sourceChannel === 'stripe' ? 'stripe' : 'manual'])->save();
                $postedPayment = $this->payments->post($financialActor, $payment, []);

                $lockedLink->update([
                    'payment_id' => $postedPayment->getKey(),
                    'status' => PaymentLinkStatus::Settled,
                    'settled_by' => $actor->getKey(),
                    'settled_at' => now(),
                    'payment_method_reference' => $methodReference,
                ]);

                $lockedTicket->update([
                    'status' => TicketStatus::Live,
                    'pending_reason' => null,
                    'updated_by' => $actor->getKey(),
                ]);

                $this->slaService->onTicketLive($lockedTicket);

                activity()
                    ->performedOn($lockedTicket)
                    ->causedBy($actor)
                    ->withChanges([
                        'old' => ['ticket_status' => TicketStatus::PendingPayment->value, 'payment_link_status' => PaymentLinkStatus::Pending->value],
                        'attributes' => ['ticket_status' => TicketStatus::Live->value, 'payment_link_status' => PaymentLinkStatus::Settled->value, 'payment_method_reference' => $methodReference],
                    ])
                    ->withProperties([
                        'source_channel' => $sourceChannel,
                        'ip_address' => request()->ip(),
                        'payment_id' => $postedPayment->getKey(),
                    ])
                    ->log('support.payment_link.settled');

                DB::afterCommit(static function () use ($lockedTicket): void {
                    if (config('support.smart_routing_enabled', false)) {
                        app(TicketRoutingService::class)->route($lockedTicket->refresh());
                    }
                });
            });
        } catch (InvalidStatusTransition $invalidStatusTransition) {
            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => $sourceChannel,
                    'ip_address' => request()->ip(),
                    'reason' => 'already_settled_or_ticket_not_pending_payment',
                ])
                ->log('support.payment_link.settlement_rejected');

            throw $invalidStatusTransition;
        }
    }

    private function resolvePaymentMethodId(?int $paymentMethodId, string $sourceChannel): int
    {
        $query = PaymentMethod::query()
            ->where('is_active', true)
            ->where('requires_proof', false)
            ->whereNotNull('chart_account_id');

        if ($paymentMethodId !== null) {
            $query->whereKey($paymentMethodId);
        } elseif ($sourceChannel === 'stripe') {
            $query->where('type', PaymentMethodType::Stripe->value);
        } else {
            $query->where('type', '!=', PaymentMethodType::Stripe->value);
        }

        $method = $query->orderBy('id')->first();

        if (! $method instanceof PaymentMethod) {
            throw new DomainException('An active payment method with a collection account and no proof requirement is required to settle this ticket.');
        }

        return $method->id;
    }

    /**
     * Cancels the pending link alongside a ticket's own
     * `pending_payment -> cancelled` transition (FR-045), called from
     * {@see TicketLifecycleService::transition()} inside that same
     * transaction — no separate audit entry, since the ticket's own
     * `support.ticket.status_changed` row already covers the action.
     *
     * Only a link that is still `Pending` is cancelled: a link already
     * settled by a concurrent payment keeps its `Settled` status.
     */
    public function cancelForTicket(Ticket $ticket): void
    {
        $link = $ticket->paymentLink()->lockForUpdate()->first();

        if ($link instanceof TicketPaymentLink && $link->status === PaymentLinkStatus::Pending) {
            $link->update(['status' => PaymentLinkStatus::Cancelled]);
        }
    }
}
