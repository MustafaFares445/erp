<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupplierPaymentStatus;
use App\Models\Concerns\TracksBlameable;
use App\Models\Concerns\TransitionsDocumentStatus;
use Database\Factories\SupplierPaymentFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $supplier_payment_number
 * @property int $supplier_id
 * @property int $payment_method_id
 * @property string $amount
 * @property string $status
 */
#[Fillable([
    'supplier_payment_number', 'supplier_id', 'payment_method_id', 'amount', 'payment_date',
    'reference', 'status',
])]
final class SupplierPayment extends Model
{
    /** @use HasFactory<SupplierPaymentFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;
    use TransitionsDocumentStatus;

    protected $attributes = ['status' => 'draft'];

    #[\Override]
    protected static function booted(): void
    {
        self::creating(function (self $payment): void {
            if (blank($payment->getAttribute('supplier_payment_number'))) {
                $maxNumber = self::query()->lockForUpdate()->max('supplier_payment_number');
                $next = is_string($maxNumber) && preg_match('/(\d+)$/', $maxNumber, $matches) === 1
                    ? ((int) $matches[1]) + 1
                    : 1;
                $payment->setAttribute('supplier_payment_number', sprintf('SPAY-%07d', $next));
            }
        });

        self::saving(function (self $payment): void {
            $originalStatus = $payment->getRawOriginal('status');
            $wasPaid = $payment->exists && $originalStatus === 'paid';
            if (! $wasPaid) {
                return;
            }

            if ($payment->isDirty(['supplier_id', 'payment_method_id', 'amount', 'payment_date', 'reference'])) {
                throw new DomainException('A paid supplier payment cannot be changed.');
            }

            $status = $payment->getAttribute('status');

            if ($status !== SupplierPaymentStatus::Paid) {
                throw new DomainException('A paid supplier payment cannot change status.');
            }
        });

        self::deleting(function (self $payment): void {
            if ($payment->isPaid()) {
                throw new DomainException('A paid supplier payment cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return HasMany<SupplierPaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return MorphOne<JournalEntry, $this> */
    public function sourceJournalEntry(): MorphOne
    {
        return $this->morphOne(JournalEntry::class, 'source');
    }

    public function isDraft(): bool
    {
        return $this->status === SupplierPaymentStatus::Draft;
    }

    public function isPaid(): bool
    {
        return $this->status === SupplierPaymentStatus::Paid;
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => SupplierPaymentStatus::class,
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }
}
