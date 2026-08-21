<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ticket creation and classification (FR-010–017,
 * contracts/ticket-lifecycle.md §2). Numbering and the chargeable/
 * non-chargeable status branch live here; a chargeable ticket's
 * {@see TicketPaymentLink} is created via
 * {@see TicketPaymentService} in the same transaction (FR-021, FR-041).
 */
final readonly class TicketIntakeService
{
    public function __construct(
        private TicketAttachmentSynchronizer $attachmentSynchronizer,
        private TicketPaymentService $paymentService,
        private SlaService $slaService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Ticket
    {
        Gate::forUser($actor)->authorize('create', Ticket::class);

        $isChargeable = (bool) ($data['is_chargeable'] ?? false);
        $amount = 0.0;
        $currency = '';

        if ($isChargeable) {
            $rawAmount = $data['amount'] ?? null;
            $rawCurrency = $data['currency'] ?? null;

            if (! is_numeric($rawAmount) || ! is_string($rawCurrency) || $rawCurrency === '') {
                throw ValidationException::withMessages([
                    'amount' => 'A chargeable ticket requires an amount and currency.',
                ]);
            }

            $amount = (float) $rawAmount;
            $currency = $rawCurrency;
        }

        return DB::transaction(function () use ($data, $actor, $isChargeable, $amount, $currency): Ticket {
            $ticket = Ticket::query()->create([
                'ticket_number' => $this->nextTicketNumber(),
                'customer_id' => $data['customer_id'],
                'type' => $data['type'],
                'priority' => $data['priority'],
                'title' => $data['title'],
                'description' => $data['description'],
                'is_chargeable' => $isChargeable,
                'status' => $isChargeable ? TicketStatus::PendingPayment : TicketStatus::Pending,
                'pending_reason' => $isChargeable ? 'Payment is awaited before this ticket can be worked.' : null,
                // FR-017: records the closed/cancelled ticket this one continues, if any.
                'continued_from_ticket_id' => $data['continued_from_ticket_id'] ?? null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            if ($isChargeable) {
                $this->paymentService->createForTicket($ticket, $amount, $currency);
            }

            if (isset($data['attachments']) && is_array($data['attachments'])) {
                $this->attachmentSynchronizer->sync($ticket, $data['attachments']);
            }

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withChanges(['attributes' => $ticket->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.ticket.created');

            return $ticket;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Ticket $ticket, array $data, User $actor): Ticket
    {
        Gate::forUser($actor)->authorize('update', $ticket);

        return DB::transaction(function () use ($ticket, $data, $actor): Ticket {
            $oldValues = $ticket->only(['type', 'priority', 'title', 'description']);
            $oldPriority = $ticket->priority;

            $ticket->fill([
                'type' => $data['type'] ?? $ticket->type,
                'priority' => $data['priority'] ?? $ticket->priority,
                'title' => $data['title'] ?? $ticket->title,
                'description' => $data['description'] ?? $ticket->description,
                'updated_by' => $actor->getKey(),
            ]);
            $ticket->save();

            if ($ticket->priority !== $oldPriority) {
                $this->slaService->onPriorityChanged($ticket, $ticket->priority, $actor);
            }

            if (isset($data['attachments']) && is_array($data['attachments'])) {
                $this->attachmentSynchronizer->sync($ticket, $data['attachments']);
            }

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withChanges([
                    'old' => $oldValues,
                    'attributes' => $ticket->only(['type', 'priority', 'title', 'description']),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.ticket.updated');

            return $ticket;
        });
    }

    private function nextTicketNumber(): string
    {
        $highestNumber = Ticket::query()->withTrashed()->whereNotNull('ticket_number')->lockForUpdate()->max('ticket_number');
        $next = is_string($highestNumber) ? ((int) mb_substr($highestNumber, 4)) + 1 : 1;

        return sprintf('TCK-%06d', $next);
    }
}
