<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TicketMessageFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only conversation entry on a {@see Ticket} (FR-030–034,
 * data-model.md §2). No `updated_at`, no update path; a correction is
 * posted as a new message.
 */
#[Fillable(['ticket_id', 'sender_user_id', 'message', 'is_internal_note'])]
final class TicketMessage extends Model
{
    /** @use HasFactory<TicketMessageFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new DomainException('Ticket messages are append-only and cannot be updated.');
        });

        self::deleting(function (): never {
            throw new DomainException('Ticket messages are append-only and cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'is_internal_note' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
