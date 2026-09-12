<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupplierDebitNoteStatus;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'debit_note_number',
    'supplier_id',
    'inventory_return_id',
    'bill_id',
    'issue_date',
    'subtotal',
    'tax_total',
    'total_amount',
    'status',
    'notes',
    'created_by',
    'updated_by',
    'confirmed_by',
    'confirmed_at',
    'reversed_by',
    'reversed_at',
])]
final class SupplierDebitNote extends Model
{
    protected $attributes = ['status' => 'draft'];

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $note): void {
            $original = SupplierDebitNoteStatus::tryFrom((string) $note->getRawOriginal('status'));
            if ($original === SupplierDebitNoteStatus::Confirmed) {
                $allowed = ['status', 'reversed_by', 'reversed_at', 'updated_by', 'updated_at'];
                if (array_diff(array_keys($note->getDirty()), $allowed) !== []) {
                    throw new DomainException('A confirmed supplier debit note cannot be edited.');
                }
            }

            if ($original === SupplierDebitNoteStatus::Reversed) {
                throw new DomainException('A reversed supplier debit note is immutable.');
            }
        });

        self::deleting(function (self $note): void {
            if ($note->status !== SupplierDebitNoteStatus::Draft) {
                throw new DomainException('A confirmed supplier debit note cannot be deleted.');
            }
        });
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'status' => SupplierDebitNoteStatus::class,
            'confirmed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<InventoryReturn, $this> */
    public function inventoryReturn(): BelongsTo
    {
        return $this->belongsTo(InventoryReturn::class);
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** @return HasMany<SupplierDebitNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierDebitNoteLine::class);
    }

    /** @return MorphMany<JournalEntry, $this> */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }
}
