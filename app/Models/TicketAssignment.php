<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketAssignmentSource;
use Database\Factories\TicketAssignmentFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ticket_id', 'employee_id', 'assigned_by', 'assigned_at',
    'assignment_source', 'support_team_id', 'routing_rule_id', 'reason',
])]
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

    #[\Override]
    public function casts(): array
    {
        return ['assignment_source' => TicketAssignmentSource::class, 'assigned_at' => 'datetime'];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return BelongsTo<SupportTeam, $this> */
    public function supportTeam(): BelongsTo
    {
        return $this->belongsTo(SupportTeam::class);
    }

    /** @return BelongsTo<SupportRoutingRule, $this> */
    public function routingRule(): BelongsTo
    {
        return $this->belongsTo(SupportRoutingRule::class, 'routing_rule_id');
    }
}
