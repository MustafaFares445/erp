<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\Favoritable;
use App\Models\Concerns\HasFavorites;
use App\Models\Concerns\TracksBlameable;
use App\Models\Concerns\TransitionsDocumentStatus;
use App\Models\Concerns\ValidatesCurrencyCatalog;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'payment_number', 'customer_id', 'payment_method_id', 'amount', 'currency', 'source',
    'payment_date', 'external_reference', 'notes', 'status', 'posted_at', 'reversed_at', 'reversed_by',
])]
/**
 * @property int $id
 * @property int $customer_id
 * @property int $payment_method_id
 * @property string $amount
 * @property Carbon $payment_date
 */
final class Payment extends Model implements Favoritable, HasMedia
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HasFavorites;
    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;
    use TransitionsDocumentStatus;
    use ValidatesCurrencyCatalog;

    protected $attributes = ['source' => 'manual', 'currency' => 'USD', 'status' => 'draft'];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function posted(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Posted->value)->whereNull('reversed_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function collectedThisMonth(Builder $query): Builder
    {
        return $query->posted()->whereBetween('posted_at', [now()->startOfMonth(), now()->endOfMonth()]);
    }

    /**
     * Posted payments with money that is currently available as a customer deposit.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function customerDeposits(Builder $query): Builder
    {
        return $query->posted()
            ->whereRaw('(select coalesce(sum(payment_allocations.amount), 0) from payment_allocations where payment_allocations.payment_id = payments.id) < payments.amount');
    }

    public function allocatedAmountMinor(): int
    {
        if ($this->relationLoaded('allocations')) {
            return $this->allocations->sum(
                static fn (PaymentAllocation $allocation): int => JournalEntryLine::toMinorUnits($allocation->amount),
            );
        }

        $allocatedAmount = $this->getAttribute('allocations_sum_amount');

        return is_numeric($allocatedAmount)
            ? JournalEntryLine::toMinorUnits($allocatedAmount)
            : JournalEntryLine::toMinorUnits($this->allocations()->sum('amount'));
    }

    public function customerDepositMinor(): int
    {
        if ($this->status !== PaymentStatus::Posted || $this->isReversed()) {
            return 0;
        }

        $amountMinor = JournalEntryLine::toMinorUnits($this->amount);

        return max(0, $amountMinor - $this->allocatedAmountMinor());
    }

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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    /** @return HasOne<PaymentTransaction, $this> */
    public function providerTransaction(): HasOne
    {
        return $this->hasOne(PaymentTransaction::class);
    }

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
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
        self::saving(static fn (self $record) => $record->validateActiveCurrency('currency'));

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
