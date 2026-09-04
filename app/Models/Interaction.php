<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InteractionDirection;
use App\Enums\InteractionOutcome;
use App\Enums\InteractionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'subject_type', 'subject_id', 'type', 'direction', 'outcome', 'occurred_at', 'summary', 'notes',
    'employee_id', 'customer_visit_id', 'ticket_id',
])]
final class Interaction extends Model
{
    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => InteractionType::class,
            'direction' => InteractionDirection::class,
            'outcome' => InteractionOutcome::class,
            'occurred_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /** @return BelongsTo<CustomerVisit, $this> */
    public function customerVisit(): BelongsTo
    {
        return $this->belongsTo(CustomerVisit::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
