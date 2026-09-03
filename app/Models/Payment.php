<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\TracksBlameable;
use App\Models\Concerns\TransitionsDocumentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'payment_number', 'customer_id', 'payment_method_id', 'amount', 'currency', 'source',
    'payment_date', 'external_reference', 'notes', 'status', 'posted_at', 'reversed_at', 'reversed_by',
])]
final class Payment extends Model implements HasMedia
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;
    use TransitionsDocumentStatus;

    protected $attributes = ['source' => 'manual', 'currency' => 'USD', 'status' => 'draft'];

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return HasMany<TaxRecognitionEntry, $this> */
    public function taxRecognitionEntries(): HasMany
    {
        return $this->hasMany(TaxRecognitionEntry::class);
    }

    /** @return MorphMany<JournalEntry, $this> */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    /** @return HasOne<ManualPaymentRecord, $this> */
    public function manualRecord(): HasOne
    {
        return $this->hasOne(ManualPaymentRecord::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2', 'payment_date' => 'date',
            'posted_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null || $this->status === PaymentStatus::Reversed;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('payment-proof')->useDisk('local');
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $payment): void {
            if ($payment->getRawOriginal('posted_at') === null) {
                return;
            }

            $allowed = ['status', 'reversed_at', 'reversed_by', 'updated_at', 'updated_by'];
            if (array_diff(array_keys($payment->getDirty()), $allowed) !== []) {
                throw new \DomainException('A posted payment cannot be edited.');
            }
        });

        self::deleting(function (self $payment): void {
            if ($payment->isPosted()) {
                throw new \DomainException('A posted payment cannot be deleted.');
            }
        });
    }
}
