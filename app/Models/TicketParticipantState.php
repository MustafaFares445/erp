<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ticket_id', 'user_id', 'last_read_message_id', 'last_read_at'])]
final class TicketParticipantState extends Model
{
    #[\Override]
    public function casts(): array
    {
        return ['last_read_at' => 'datetime'];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<TicketMessage, $this> */
    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'last_read_message_id');
    }
}
