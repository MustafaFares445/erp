<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TaxRecognitionEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'tax_date', 'direction', 'tax_type', 'tax_amount', 'source_type', 'source_id',
    'invoice_id', 'payment_id', 'refund_id', 'journal_entry_id', 'payment_amount',
    'recognised_tax_amount', 'recognition_date',
])]
final class TaxRecognitionEntry extends Model
{
    /** @use HasFactory<TaxRecognitionEntryFactory> */
    use HasFactory;

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'tax_date' => 'date', 'tax_amount' => 'decimal:2', 'payment_amount' => 'decimal:2',
            'recognised_tax_amount' => 'decimal:2', 'recognition_date' => 'date',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(static function (self $entry): void {
            $dirty = array_keys($entry->getDirty());
            $allowed = ['journal_entry_id', 'updated_at'];

            if ($entry->getRawOriginal('journal_entry_id') === null && array_diff($dirty, $allowed) === []) {
                return;
            }

            throw new \DomainException('Tax recognition entries are append-only.');
        });

        self::deleting(static function (): never {
            throw new \DomainException('Tax recognition entries are append-only.');
        });
    }
}
