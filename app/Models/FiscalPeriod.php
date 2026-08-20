<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\JournalPostingService;
use Database\Factories\FiscalPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named date range that owns the "may anything still be posted here?"
 * question.
 *
 * `is_closed` is the whole lifecycle — the ERD's generic `status` column is
 * deliberately absent (ERD deviation E-1, ADR 0007). Closing and reopening go
 * through {@see FiscalPeriodService} so both are audited;
 * {@see JournalPostingService} reads `is_closed` to refuse a posting or a
 * reversal into a closed period (FR-023).
 *
 * @see /specs/018-chart-of-accounts-journals/data-model.md §4
 */
/**
 * @property int $id
 * @property string $name
 * @property bool $is_closed
 * @property Collection<int, JournalEntry> $journalEntries
 */
#[Fillable([
    'name',
    'starts_at',
    'ends_at',
    'is_closed',
])]
final class FiscalPeriod extends Model
{
    /** @use HasFactory<FiscalPeriodFactory> */
    use HasFactory;

    use TracksBlameable;

    /** @return HasMany<JournalEntry, $this> */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'is_closed' => 'boolean',
        ];
    }
}
