<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundStatus;
use App\Models\Concerns\TracksBlameable;
use App\Models\Concerns\TransitionsDocumentStatus;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'refund_number', 'customer_id', 'credit_note_id', 'invoice_id', 'payment_method_id',
    'refund_date', 'amount', 'reason', 'status', 'journal_entry_id',
    'approved_by', 'approved_at', 'paid_by', 'paid_at',
])]
final class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;
    use TransitionsDocumentStatus;

    protected $attributes = ['status' => 'draft'];

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** @return MorphMany<TaxRecognitionEntry, $this> */
    public function taxRecognitionEntries(): MorphMany
    {
        return $this->morphMany(TaxRecognitionEntry::class, 'source');
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'refund_date' => 'date', 'amount' => 'decimal:2',
            'approved_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === RefundStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === RefundStatus::Approved;
    }

    public function isPaid(): bool
    {
        return $this->status === RefundStatus::Paid;
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $refund): void {
            if (! in_array($refund->getRawOriginal('status'), ['approved', 'paid'], true)) {
                return;
            }

            if ($refund->isDirty([
                'refund_number', 'customer_id', 'credit_note_id', 'invoice_id', 'payment_method_id',
                'refund_date', 'amount', 'reason',
            ])) {
                throw new \DomainException('An approved or paid refund cannot be edited.');
            }
        });

        self::deleting(function (self $refund): void {
            if (! $refund->isDraft()) {
                throw new \DomainException('An approved or paid refund cannot be deleted.');
            }
        });
    }
}
