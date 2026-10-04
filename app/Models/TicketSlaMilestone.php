<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SlaMilestoneKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ticket_id', 'sla_policy_id', 'key', 'target_minutes', 'started_at', 'due_at',
    'paused_at', 'paused_seconds', 'completed_at', 'breached_at',
    'at_risk_notified_at', 'breach_notified_at', 'metadata',
])]
final class TicketSlaMilestone extends Model
{
    #[\Override]
    public function casts(): array
    {
        return [
            'key' => SlaMilestoneKey::class,
            'started_at' => 'datetime',
            'due_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'breached_at' => 'datetime',
            'at_risk_notified_at' => 'datetime',
            'breach_notified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }
}
