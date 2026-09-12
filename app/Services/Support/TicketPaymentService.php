<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\PaymentLinkStatus;
use App\Enums\TicketStatus;
use App\Models\PaymentMethod;
use App\Models\SalesSetting;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Sales\InvoiceService;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Chargeable-ticket settlement.
 *
 * WP-4.7 deliberately eliminates the former second revenue path: settlement
 * creates and issues a standard Sales invoice, then creates/posts a standard
 * Payment allocated to that invoice. Revenue, receivables, cash, and
 * proportional tax therefore use exactly the same services as every other
 * customer transaction.
 */
final readonly class TicketPaymentService
{
    public function __construct(
        private SlaService $slaService,
        private InvoiceService $invoiceService,
        private PaymentService $paymentService,
    ) {}

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
     * @param  int|null  $paymentMethodId  Internal ERP payment method used for the
     *                                     accounting cash/bank leg. When omitted, settlement is allowed only when
     *                                     exactly one active, proof-free payment method exists; ambiguity is never
     *                                     resolved silently.
     */
    public function settle(
        TicketPaymentLink $link,
        string $methodReference,
        User $actor,
        ?int $paymentMethodId = null,
    ): void {
        $ticket = $link->ticket;

        // @codeCoverageIgnoreStart
        if (! $ticket instanceof Ticket) {
            throw new LogicException('A TicketPaymentLink must always belong to a Ticket.');
        }
        // @codeCoverageIgnoreEnd

        Gate::forUser($actor)->authorize('settlePayment', $ticket);

        try {
            DB::transaction(function () use ($link, $actor, $methodReference, $paymentMethodId): void {
                /** @var TicketPaymentLink $lockedLink */
                $lockedLink = TicketPaymentLink::query()
                    ->whereKey($link->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                /** @var Ticket $lockedTicket */
                $lockedTicket = Ticket::query()
                    ->whereKey($lockedLink->ticket_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedLink->status !== PaymentLinkStatus::Pending || $lockedTicket->status !== TicketStatus::PendingPayment) {
                    throw InvalidStatusTransition::fromTo($lockedLink->status->value, PaymentLinkStatus::Settled->value);
                }

                if (! is_int($lockedTicket->customer_id)) {
                    throw new DomainException('A chargeable ticket must belong to a customer before settlement.');
                }

                $method = $this->resolvePaymentMethod($lockedLink, $paymentMethodId);
                [$netAmount, $taxAmount] = $this->splitInclusiveTax((string) $lockedLink->amount);

                $invoice = $this->invoiceService->createStandalone($actor, [
                    'customer_id' => $lockedTicket->customer_id,
                    'invoice_date' => now()->toDateString(),
                    'description' => 'Support ticket '.$lockedTicket->ticket_number,
                ], [[
                    'description' => 'Support ticket '.$lockedTicket->ticket_number,
                    'quantity' => 1,
                    'unit_price' => $netAmount,
                    'tax_amount' => $taxAmount,
                ]]);
                $invoice = $this->invoiceService->issue($actor, $invoice);

                $payment = $this->paymentService->createDraft($actor, [
                    'customer_id' => $lockedTicket->customer_id,
                    'payment_method_id' => $method->getKey(),
                    'amount' => (float) $lockedLink->amount,
                    'currency' => (string) $lockedLink->currency,
                    'payment_date' => now()->toDateString(),
                    'external_reference' => $methodReference,
                    'notes' => 'Settlement for support ticket '.$lockedTicket->ticket_number,
                ]);
                $payment = $this->paymentService->post($actor, $payment, [[
                    'invoice_id' => (int) $invoice->getKey(),
                    'amount' => (float) $lockedLink->amount,
                ]]);

                $lockedLink->update([
                    'status' => PaymentLinkStatus::Settled,
                    'settled_by' => $actor->getKey(),
                    'settled_at' => now(),
                    'payment_method_reference' => $methodReference,
                    'payment_method_id' => $method->getKey(),
                    'invoice_id' => $invoice->getKey(),
                    'payment_id' => $payment->getKey(),
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
                        'old' => [
                            'ticket_status' => TicketStatus::PendingPayment->value,
                            'payment_link_status' => PaymentLinkStatus::Pending->value,
                        ],
                        'attributes' => [
                            'ticket_status' => TicketStatus::Live->value,
                            'payment_link_status' => PaymentLinkStatus::Settled->value,
                            'payment_method_reference' => $methodReference,
                            'invoice_id' => $invoice->getKey(),
                            'payment_id' => $payment->getKey(),
                        ],
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

    public function cancelForTicket(Ticket $ticket): void
    {
        $ticket->paymentLink?->update(['status' => PaymentLinkStatus::Cancelled]);
    }

    private function resolvePaymentMethod(TicketPaymentLink $link, ?int $requestedId): PaymentMethod
    {
        $id = $requestedId ?? $link->payment_method_id;

        if ($id !== null) {
            $method = PaymentMethod::query()
                ->whereKey($id)
                ->where('is_active', true)
                ->where('requires_proof', false)
                ->first();

            if ($method instanceof PaymentMethod) {
                return $method;
            }

            throw new DomainException('The selected ticket settlement payment method is not active or requires proof.');
        }

        $methods = PaymentMethod::query()
            ->where('is_active', true)
            ->where('requires_proof', false)
            ->whereNotNull('chart_account_id')
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($methods->count() !== 1 || ! $methods->first() instanceof PaymentMethod) {
            throw new DomainException('Select an internal payment method before settling the ticket.');
        }

        /** @var PaymentMethod $method */
        $method = $methods->first();

        return $method;
    }

    /** @return array{0:float,1:float} */
    private function splitInclusiveTax(string $gross): array
    {
        $grossAmount = round((float) $gross, 2);
        $taxPercent = (float) SalesSetting::current()->default_tax_percent;

        if ($taxPercent <= 0.0) {
            return [$grossAmount, 0.0];
        }

        $net = round($grossAmount / (1 + ($taxPercent / 100)), 2);

        return [$net, round($grossAmount - $net, 2)];
    }
}
