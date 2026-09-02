<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'invoice_number', 'customer_id', 'inventory_operation_id', 'order_id', 'payment_term_id',
    'invoice_date', 'due_date', 'description', 'subtotal', 'tax_total', 'total_amount',
    'amount_paid', 'credited_amount', 'recognised_tax_amount', 'status', 'issued_at', 'sent_at',
])]
final class Invoice extends Model implements HasMedia
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;
    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;

    protected $attributes = [
        'status' => 'draft', 'subtotal' => 0, 'tax_total' => 0, 'total_amount' => 0,
        'amount_paid' => 0, 'credited_amount' => 0, 'recognised_tax_amount' => 0,
    ];

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo { return $this->belongsTo(CustomerProfile::class); }
    /** @return BelongsTo<InventoryOperation, $this> */
    public function inventoryOperation(): BelongsTo { return $this->belongsTo(InventoryOperation::class); }
    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    /** @return BelongsTo<PaymentTerm, $this> */
    public function paymentTerm(): BelongsTo { return $this->belongsTo(PaymentTerm::class); }
    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany { return $this->hasMany(InvoiceLine::class); }
    /** @return HasMany<InvoiceConfirmation, $this> */
    public function confirmations(): HasMany { return $this->hasMany(InvoiceConfirmation::class); }
    /** @return HasMany<PaymentAllocation, $this> */
    public function paymentAllocations(): HasMany { return $this->hasMany(PaymentAllocation::class); }
    /** @return HasMany<CreditNote, $this> */
    public function creditNotes(): HasMany { return $this->hasMany(CreditNote::class); }
    /** @return HasMany<TaxRecognitionEntry, $this> */
    public function taxRecognitionEntries(): HasMany { return $this->hasMany(TaxRecognitionEntry::class); }
    /** @return MorphMany<JournalEntry, $this> */
    public function journalEntries(): MorphMany { return $this->morphMany(JournalEntry::class, 'source'); }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date', 'due_date' => 'date', 'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2', 'total_amount' => 'decimal:2', 'amount_paid' => 'decimal:2',
            'credited_amount' => 'decimal:2', 'recognised_tax_amount' => 'decimal:2',
            'issued_at' => 'datetime', 'sent_at' => 'datetime',
        ];
    }

    public function outstandingAmount(): float
    {
        return max(0.0, (float) $this->total_amount - (float) $this->amount_paid - (float) $this->credited_amount);
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isIssued(): bool { return $this->issued_at !== null; }

    public function isOverdue(?Carbon $asOf = null): bool
    {
        if (! $this->isIssued() || $this->outstandingAmount() <= 0.0 || ! $this->due_date instanceof Carbon) {
            return false;
        }

        $asOf ??= now();

        return $this->paymentTerm instanceof PaymentTerm
            ? $this->paymentTerm->isOverdueAt($this->due_date, $asOf)
            : $asOf->greaterThan($this->due_date);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('invoice-pdf')->useDisk('local');
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $invoice): void {
            if ($invoice->getRawOriginal('issued_at') === null) {
                return;
            }

            if ($invoice->isDirty([
                'customer_id', 'inventory_operation_id', 'order_id', 'payment_term_id',
                'invoice_date', 'due_date', 'subtotal', 'tax_total', 'total_amount',
            ])) {
                throw new \DomainException('An issued invoice cannot be changed.');
            }
        });

        self::deleting(function (self $invoice): void {
            if ($invoice->isIssued()) {
                throw new \DomainException('An issued invoice cannot be deleted.');
            }
        });
    }
}
