<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\TicketParticipantState;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class TicketReadStateService
{
    public function markPublicConversationRead(Ticket $ticket, User $user): void
    {
        $latestMessageId = $ticket->messages()
            ->where('is_internal_note', false)
            ->max('id');

        TicketParticipantState::query()->updateOrCreate(
            ['ticket_id' => $ticket->getKey(), 'user_id' => $user->getKey()],
            [
                'last_read_message_id' => is_numeric($latestMessageId) ? (int) $latestMessageId : null,
                'last_read_at' => now(),
            ],
        );
    }

    public function unreadPublicCount(Ticket $ticket, User $user): int
    {
        $state = TicketParticipantState::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        $lastReadMessageId = $state?->last_read_message_id;

        return $ticket->messages()
            ->where('is_internal_note', false)
            ->when(
                $lastReadMessageId !== null,
                static fn (Builder $query) => $query->where('id', '>', $lastReadMessageId),
            )
            ->where('sender_user_id', '!=', $user->getKey())
            ->count();
    }
}
