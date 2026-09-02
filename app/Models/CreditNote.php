<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Database\Factories\CreditNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'credit_note_number', 'invoice_id', 'customer_id', 'reason', 'issue_date',
    'subtotal', 'tax_total', 'grand_total', 'status', 'confirmed_at', 'reversed_at',
])]
final class CreditNote extends Model implements HasMedia
{
    /** @use HasFactory<CreditNoteFactory> */
    use HasFactory;
    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;

    protected $attributes = ['status' => 'draft'];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo { return $this->belongsTo(CustomerProfile::class); }
    /** @return HasMany<CreditNoteLine, $this> */
    public function lines(): HasMany { return $this->hasMany(CreditNoteLine::class); }
    /** @return MorphMany<JournalEntry, $this> */
    public function journalEntries(): MorphMany { return $this->morphMany(JournalEntry::class, 'source'); }
    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany { return $this->hasMany(Refund::class); }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'subtotal' => 'decimal:2', 'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2', 'confirmed_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function isConfirmed(): bool { return $this->confirmed_at !== null; }
    public function isReversed(): bool { return $this->reversed_at !== null || $this->status === 'reversed'; }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('credit-note-pdf')->useDisk('local');
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $creditNote): void {
            if ($creditNote->getRawOriginal('confirmed_at') === null) {
                return;
            }

            $allowed = ['status', 'reversed_at', 'updated_at', 'updated_by'];
            if (array_diff(array_keys($creditNote->getDirty()), $allowed) !== []) {
                throw new \DomainException('A confirmed credit note cannot be edited.');
            }
        });

        self::deleting(function (self $creditNote): void {
            if ($creditNote->isConfirmed()) {
                throw new \DomainException('A confirmed credit note cannot be deleted.');
            }
        });
    }
}
