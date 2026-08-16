<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\PaymentLinkStatus;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Chargeable-ticket payment holds and settlement (FR-040–048,
 * contracts/ticket-lifecycle.md §5). No Stripe integration and no
 * accounting/journal/tax side effect exists anywhere in this class (D4,
 * FR-046, SC-004) — settlement touches only `ticket_payment_links` and
 * `tickets`.
 */
final readonly class TicketPaymentService
{
    public function __construct(private SlaService $slaService) {}

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
            'currency' => $currency,
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
     * pattern already used by {@see TicketIntakeService::nextTicketNumber()}
     * and {@see InventoryBalanceService}.
     */
    public function settle(TicketPaymentLink $link, string $methodReference, User $actor): void
    {
        $ticket = $link->ticket;

        // @codeCoverageIgnoreStart
        // ticket_payment_links.ticket_id is NOT NULL and foreign-key constrained.
        if (! $ticket instanceof Ticket) {
            throw new LogicException('A TicketPaymentLink must always belong to a Ticket.');
        }

        // @codeCoverageIgnoreEnd

        Gate::forUser($actor)->authorize('settlePayment', $ticket);

        try {
            DB::transaction(function () use ($link, $actor, $methodReference): void {
                $lockedLink = TicketPaymentLink::query()->whereKey($link->getKey())->lockForUpdate()->firstOrFail();
                $lockedTicket = Ticket::query()->whereKey($lockedLink->ticket_id)->lockForUpdate()->firstOrFail();

                if ($lockedLink->status !== PaymentLinkStatus::Pending || $lockedTicket->status !== TicketStatus::PendingPayment) {
                    throw InvalidStatusTransition::fromTo($lockedLink->status->value, PaymentLinkStatus::Settled->value);
                }

                $lockedLink->update([
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
                    ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                    ->log('support.payment_link.settled');
            });
        } catch (InvalidStatusTransition $invalidStatusTransition) {
            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'ip_address' => request()->ip(),
                    'reason' => 'already_settled_or_ticket_not_pending_payment',
                ])
                ->log('support.payment_link.settlement_rejected');

            throw $invalidStatusTransition;
        }
    }

    /**
     * Cancels the pending link alongside a ticket's own
     * `pending_payment -> cancelled` transition (FR-045), called from
     * {@see TicketLifecycleService::transition()} inside that same
     * transaction — no separate audit entry, since the ticket's own
     * `support.ticket.status_changed` row already covers the action.
     */
    public function cancelForTicket(Ticket $ticket): void
    {
        $ticket->paymentLink?->update(['status' => PaymentLinkStatus::Cancelled]);
    }
}
