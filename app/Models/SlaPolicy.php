<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketPriority;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\SlaPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 4 fixed rows, one per {@see TicketPriority} (FR-051). Read only at a
 * ticket's clock-start and priority-change — never joined live — so an edit
 * here never changes an already-started ticket's due times (SC-006,
 * contracts/ticket-lifecycle.md §6).
 */
#[Fillable([
    'priority',
    'response_target_minutes',
    'resolution_target_minutes',
    'updated_by',
])]
final class SlaPolicy extends Model
{
    /** @use HasFactory<SlaPolicyFactory> */
    use HasFactory;

    /**
     * Rows are seeded, not user-created (data-model.md §5 — no
     * `created_by`), so only `updated_by` is tracked here rather than
     * reusing {@see TracksBlameable}, which assumes
     * both columns exist.
     */
    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $policy): void {
            $policy->setAttribute('updated_by', auth()->id());
        });
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
