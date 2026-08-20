<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JournalEntryStatus;
use App\Models\Concerns\TracksBlameable;
use App\Services\Accounting\Exceptions\PostedEntryIsImmutable;
use App\Services\Accounting\JournalPostingService;
use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A balanced set of debit/credit lines with a one-way `draft` -> `posted`
 * lifecycle.
 *
 * There is no `deleted_at`: a posted entry is immutable and undeletable
 * (FR-025), and a draft is hard-deleted because it was never in the ledger.
 * `fiscal_period_id` stays null until posting, when it is resolved from
 * `entry_date` (research.md R-004).
 *
 * The `source` morph carries whichever document produced the entry. For a
 * reversal it points at the entry being reversed, which is why no dedicated
 * reversal column exists (research.md R-003) — {@see self::reversal()} reads
 * the morph from the other side.
 *
 * @see JournalPostingService
 * @see /specs/018-chart-of-accounts-journals/contracts/journal-posting.md §4
 */
/**
 * @property int $id
 * @property int|null $fiscal_period_id
 * @property string $entry_number
 * @property string|null $description
 * @property string|null $source_type
 * @property int|null $source_id
 * @property FiscalPeriod|null $fiscalPeriod
 * @property Collection<int, JournalEntryLine> $lines
 * @property self|null $reversal
 */
#[Fillable([
    'fiscal_period_id',
    'entry_number',
    'entry_date',
    'description',
    'source_type',
    'source_id',
    'status',
])]
final class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory;

    use TracksBlameable;

    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * Generates the entry number on create and enforces posted-entry
     * immutability on every other write path (FR-025).
     *
     * The `updating` guard reads the **persisted** status, not the in-memory
     * one, so it permits exactly one transition — a row stored as `draft`
     * becoming `posted` — and refuses every write to a row already stored as
     * `posted`, whatever the attempted change. That is what makes the ledger
     * append-only against code that never went through
     * {@see JournalPostingService}.
     */
    #[\Override]
    protected static function booted(): void
    {
        self::creating(function (self $entry): void {
            if (blank($entry->getAttribute('entry_number'))) {
                $entry->setAttribute('entry_number', self::nextEntryNumber());
            }
        });

        self::updating(function (self $entry): void {
            $entry->guardAgainstPostedWrite();
        });

        self::deleting(function (self $entry): void {
            $entry->guardAgainstPostedWrite();
        });
    }

    /**
     * Next sequential entry number, mirroring
     * `InventoryOperationService::nextOperationNumber()` (research.md R-009).
     *
     * The zero-padded format makes the lexical `max()` agree with the numeric
     * one. `lockForUpdate()` serialises concurrent creations inside the caller's
     * transaction; the unique index on `entry_number` is the backstop if two
     * ever race outside one.
     */
    public static function nextEntryNumber(): string
    {
        $maxNumber = self::query()->lockForUpdate()->max('entry_number');

        return sprintf('JE-%06d', is_string($maxNumber) ? (int) mb_substr($maxNumber, 3) + 1 : 1);
    }

    /**
     * Refuses the write when the row is *stored* as posted.
     *
     * Reads the persisted status rather than the in-memory one, so it permits
     * exactly one transition — a row stored as `draft` becoming `posted` — and
     * refuses every write to a row already stored as `posted`.
     */
    public function guardAgainstPostedWrite(): void
    {
        if ($this->getRawOriginal('status') !== JournalEntryStatus::Posted->value) {
            return;
        }

        $entryNumber = $this->getRawOriginal('entry_number');

        // Named by its id if the stored number is somehow not a string, which the
        // NOT NULL column prevents — a refusal must still say which row it was.
        throw PostedEntryIsImmutable::forEntry(is_string($entryNumber) ? $entryNumber : '#'.$this->id);
    }

    /** @return HasMany<JournalEntryLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<FiscalPeriod, $this> */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    /**
     * The document that produced this entry — or, for a reversal, the entry it
     * reverses.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The posted entry that reverses this one, if any (FR-028).
     *
     * Deliberately scoped to posted entries: a draft whose source happens to
     * point here is not in the ledger and must not block a real reversal.
     *
     * @return MorphOne<self, $this>
     */
    public function reversal(): MorphOne
    {
        return $this->morphOne(self::class, 'source')
            ->where('status', JournalEntryStatus::Posted->value);
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

    public function isPosted(): bool
    {
        return $this->getAttribute('status') === JournalEntryStatus::Posted;
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'status' => JournalEntryStatus::class,
        ];
    }
}
