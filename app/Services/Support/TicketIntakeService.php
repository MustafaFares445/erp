<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Fast ticket intake. Commercial, equipment, warranty and service-path
 * decisions are deliberately deferred to TicketTriageService.
 */
final readonly class TicketIntakeService
{
    public function __construct(
        private TicketAttachmentSynchronizer $attachmentSynchronizer,
        private SlaService $slaService,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Ticket
    {
        Gate::forUser($actor)->authorize('create', Ticket::class);

        return DB::transaction(function () use ($data, $actor): Ticket {
            $ticket = Ticket::query()->create([
                'ticket_number' => $this->nextTicketNumber(),
                'customer_id' => $data['customer_id'],
                'type' => $data['type'],
                'customer_impact' => $data['customer_impact'] ?? null,
                'priority' => $data['priority'],
                'title' => $data['title'],
                'description' => $data['description'],
                'is_chargeable' => false,
                'status' => TicketStatus::Pending,
                'pending_reason' => null,
                'continued_from_ticket_id' => $data['continued_from_ticket_id'] ?? null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

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

    /** @param array<string, mixed> $data */
    public function update(Ticket $ticket, array $data, User $actor): Ticket
    {
        Gate::forUser($actor)->authorize('update', $ticket);

        return DB::transaction(function () use ($ticket, $data, $actor): Ticket {
            $oldValues = $ticket->only(['type', 'customer_impact', 'priority', 'title', 'description']);
            $oldPriority = $ticket->priority;

            $ticket->fill([
                'type' => $data['type'] ?? $ticket->type,
                'customer_impact' => $data['customer_impact'] ?? $ticket->customer_impact,
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
                    'attributes' => $ticket->only(['type', 'customer_impact', 'priority', 'title', 'description']),
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
