<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Models\Concerns\TracksBlameable;
use App\Services\Sales\Exceptions\QuotationImmutable;
use App\Services\Sales\QuotationService;
use Database\Factories\QuotationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A priced offer to a customer (data-model.md §4).
 *
 * Content is frozen from {@see QuotationStatus::Sent} onward (FR-023): this
 * model refuses to update `customer_id` or any total once the status has
 * left `draft` — a second line of defence behind
 * {@see QuotationService} — and {@see QuotationLine}
 * refuses to update or delete a line under the same condition. Never touches
 * stock in any state (FR-020): there is no relation here to a warehouse, a
 * reservation, or a movement, by design.
 */
#[Fillable([
    'quotation_number', 'customer_id', 'employee_id', 'sales_opportunity_id', 'payment_term_id',
    'issue_date', 'expires_at', 'notes', 'subtotal', 'tax_total', 'grand_total', 'status',
    'sent_at', 'decided_at', 'decision_note', 'decided_by', 'converted_order_id',
])]
final class Quotation extends Model
{
    /** @use HasFactory<QuotationFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    private const array FROZEN_ONCE_SENT = ['customer_id', 'subtotal', 'tax_total', 'grand_total'];

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'issue_date' => 'date',
            'expires_at' => 'date',
            'sent_at' => 'datetime',
            'decided_at' => 'date',
        ];
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /** @return BelongsTo<SalesOpportunity, $this> */
    public function salesOpportunity(): BelongsTo
    {
        return $this->belongsTo(SalesOpportunity::class);
    }

    /** @return BelongsTo<PaymentTerm, $this> */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<Order, $this> */
    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }

    /** @return HasMany<QuotationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    /** @return MorphMany<SupplierConfirmation, $this> */
    public function confirmations(): MorphMany
    {
        return $this->morphMany(SupplierConfirmation::class, 'confirmable');
    }

    /**
     * FR-022: derived rather than only stored — a `sent` quotation whose
     * `expires_at` has passed presents as expired even though nothing has
     * rewritten its row.
     */
    public function isExpired(): bool
    {
        if ($this->status === QuotationStatus::Expired) {
            return true;
        }

        return $this->status === QuotationStatus::Sent
            && $this->expires_at?->isPast() === true;
    }

    /**
     * Was this quotation ever sent? {@see QuotationLine} consults this rather
     * than duplicating the `draft`-only check, since a line has no status of
     * its own.
     */
    public function isFrozen(): bool
    {
        return $this->getRawOriginal('status') !== QuotationStatus::Draft->value;
    }

    public function guardAgainstFrozenWrite(): void
    {
        if (! $this->isFrozen()) {
            return;
        }

        foreach (self::FROZEN_ONCE_SENT as $attribute) {
            if ($this->isDirty($attribute)) {
                throw QuotationImmutable::forQuotation((string) $this->quotation_number);
            }
        }
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $quotation): void {
            $quotation->guardAgainstFrozenWrite();
        });
    }
}
