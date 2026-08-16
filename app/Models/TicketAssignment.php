<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TicketAssignmentFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only assignment history of a {@see Ticket} (FR-023,
 * data-model.md §3). No `updated_at`, no update path — the current
 * assignee always reflects the newest row (FR-024).
 */
#[Fillable(['ticket_id', 'employee_id', 'assigned_by', 'assigned_at'])]
final class TicketAssignment extends Model
{
    /** @use HasFactory<TicketAssignmentFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new DomainException('Ticket assignment records are append-only and cannot be updated.');
        });

        self::deleting(function (): never {
            throw new DomainException('Ticket assignment records are append-only and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
