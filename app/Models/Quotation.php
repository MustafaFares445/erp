<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Models\Concerns\TracksBlameable;
use App\Services\Sales\Exceptions\QuotationImmutable;
use Database\Factories\QuotationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'quotation_number', 'customer_id', 'employee_id', 'sales_opportunity_id', 'payment_term_id',
    'issue_date', 'expires_at', 'notes', 'subtotal', 'tax_total', 'grand_total', 'status',
    'sent_at', 'decided_at', 'decision_note', 'decided_by', 'converted_order_id', 'requoted_from_id',
    'opportunity_title_snapshot', 'opportunity_estimated_value_minor_snapshot',
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
            'issue_date' => 'date', 'expires_at' => 'date', 'sent_at' => 'datetime', 'decided_at' => 'date',
            'opportunity_estimated_value_minor_snapshot' => 'integer',
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

    /** @return BelongsTo<Quotation, $this> */
    public function requotedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'requoted_from_id');
    }

    /** @return HasMany<Quotation, $this> */
    public function requotes(): HasMany
    {
        return $this->hasMany(self::class, 'requoted_from_id');
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

    public function hasLapsedReservations(): bool
    {
        $order = $this->relationLoaded('convertedOrder') ? $this->convertedOrder : $this->convertedOrder()->with('deliveries.reservations')->first();
        if (! $order instanceof Order) {
            return false;
        }
        if (! $order->relationLoaded('deliveries')) {
            $order->load('deliveries.reservations');
        }

        return $order->hasLapsedReservations();
    }

    public function isExpired(): bool
    {
        if ($this->status === QuotationStatus::Expired) {
            return true;
        }

        return $this->status === QuotationStatus::Sent && $this->expires_at?->isPast() === true;
    }

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
        self::updating(static function (self $quotation): void {
            $quotation->guardAgainstFrozenWrite();
        });
    }
}
