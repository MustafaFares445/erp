<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Ticket conversation thread (FR-030–035,
 * contracts/ticket-lifecycle.md §4).
 */
final readonly class TicketMessageService
{
    public function post(Ticket $ticket, string $body, bool $isInternalNote, User $actor): TicketMessage
    {
        Gate::forUser($actor)->authorize('message', $ticket);

        return DB::transaction(function () use ($ticket, $body, $isInternalNote, $actor): TicketMessage {
            $message = TicketMessage::query()->create([
                'ticket_id' => $ticket->getKey(),
                'sender_user_id' => $actor->getKey(),
                'message' => $body,
                'is_internal_note' => $isInternalNote,
            ]);

            if (! $isInternalNote && $ticket->first_response_at === null) {
                $ticket->first_response_at = now();
                $ticket->save();
            }

            activity()
                ->performedOn($ticket)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['message_id' => $message->getKey(), 'is_internal_note' => $isInternalNote]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.ticket.message_posted');

            return $message;
        });
    }
}
