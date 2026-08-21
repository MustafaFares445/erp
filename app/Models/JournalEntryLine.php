<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JournalEntryStatus;
use App\Services\Accounting\Exceptions\PostedEntryIsImmutable;
use Database\Factories\JournalEntryLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One debit-or-credit amount against one account, ordered within its entry.
 *
 * Exactly one of `debit`/`credit` is non-zero, checked at posting rather than on
 * save: a draft is allowed to be incomplete, which is what makes it a draft
 * (research.md R-012).
 *
 * @see /specs/018-chart-of-accounts-journals/data-model.md §6
 */
/**
 * @property int $id
 * @property int $journal_entry_id
 * @property int $chart_account_id
 * @property string|null $description
 * @property JournalEntry|null $journalEntry
 * @property ChartAccount|null $chartAccount
 */
#[Fillable([
    'journal_entry_id',
    'chart_account_id',
    'debit',
    'credit',
    'description',
    'sort_order',
])]
final class JournalEntryLine extends Model
{
    /** @use HasFactory<JournalEntryLineFactory> */
    use HasFactory;

    /**
     * Refuses every write touching a line whose parent entry is posted
     * (FR-025).
     *
     * `creating` is guarded as well as `updating`/`deleting`, because appending
     * a line to a posted entry would silently unbalance it — the one way to
     * corrupt the ledger without modifying any existing row. A reversal's lines
     * are written while its entry is still a draft, so they are unaffected.
     */
    #[\Override]
    protected static function booted(): void
    {
        self::creating(function (self $line): void {
            self::guardAgainstPostedParent($line->journal_entry_id);
        });

        self::updating(function (self $line): void {
            self::guardAgainstPostedParent($line->journal_entry_id);

            $originalParent = self::storedParentIdOf($line);

            // A line moved between entries has to clear both ends, or it could be
            // lifted out of a posted entry and unbalance it on the way out.
            if ($originalParent !== null && $originalParent !== $line->journal_entry_id) {
                self::guardAgainstPostedParent($originalParent);
            }
        });

        self::deleting(function (self $line): void {
            self::guardAgainstPostedParent(self::storedParentIdOf($line));
        });
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class);
    }

    /**
     * This line's signed contribution in integer minor units: debits positive,
     * credits negative, before the account type's normal-balance sign is
     * applied (FR-030).
     */
    public function signedMinorUnits(): int
    {
        return self::toMinorUnits($this->getAttribute('debit'))
            - self::toMinorUnits($this->getAttribute('credit'));
    }

    /**
     * Money is compared and summed only as integer minor units, never as
     * floats: a two-line entry of `33.33 / 33.33` must balance while
     * `33.33 / 33.34` must not, and float arithmetic cannot be trusted to tell
     * those apart (research.md R-001).
     */
    public static function toMinorUnits(mixed $amount): int
    {
        // A null or absent amount is zero, which is what an unfilled side of a
        // draft line means. Nothing else can reach a `decimal(15,2)` column.
        if (! is_numeric($amount)) {
            return 0;
        }

        return (int) round(((float) $amount) * 100);
    }

    /**
     * Reads the parent's persisted status directly rather than through the
     * relation, so a stale loaded relation cannot let a write slip past.
     */
    private static function guardAgainstPostedParent(?int $journalEntryId): void
    {
        if ($journalEntryId === null) {
            return;
        }

        $entryNumber = JournalEntry::query()
            ->whereKey($journalEntryId)
            ->where('status', JournalEntryStatus::Posted->value)
            ->value('entry_number');

        // Null means no posted entry has that id, so there is nothing to refuse.
        if (is_string($entryNumber)) {
            throw PostedEntryIsImmutable::forLineOf($entryNumber);
        }
    }

    /**
     * The parent id as currently stored, or null on a line that has never been
     * saved.
     */
    private static function storedParentIdOf(self $line): ?int
    {
        $stored = $line->getRawOriginal('journal_entry_id');

        return is_numeric($stored) ? (int) $stored : null;
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}
