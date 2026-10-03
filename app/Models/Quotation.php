<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Models\Concerns\Favoritable;
use App\Models\Concerns\HasFavorites;
use App\Models\Concerns\TracksBlameable;
use App\Services\Sales\Exceptions\QuotationImmutable;
use Database\Factories\QuotationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
    'quotation_number', 'customer_id', 'employee_id', 'sales_opportunity_id', 'payment_term_id',
    'issue_date', 'expires_at', 'notes', 'subtotal', 'tax_total', 'grand_total', 'status',
    'sent_at', 'decided_at', 'decision_note', 'decided_by', 'converted_order_id', 'requoted_from_id',
    'opportunity_title_snapshot', 'opportunity_estimated_value_minor_snapshot',
])]
/**
 * @property int $id
 * @property string $quotation_number
 * @property int $customer_id
 * @property int|null $converted_order_id
 * @property QuotationStatus $status
 */
final class Quotation extends Model implements Favoritable, HasMedia
{
    /** @use HasFactory<QuotationFactory> */
    use HasFactory;

    use HasFavorites;
    use InteractsWithMedia;
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

    /**
     * Quotations that have not reached a terminal status — the "open
     * quotation value" the Sales team is still carrying.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            QuotationStatus::Draft->value,
            QuotationStatus::Sent->value,
            QuotationStatus::Accepted->value,
            QuotationStatus::ChangesRequested->value,
        ]);
    }

    /**
     * Sent to the customer but without a final decision recorded yet.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->where('status', QuotationStatus::Sent->value);
    }

    /**
     * Accepted by the customer but not yet converted to an order — the
     * operationally important "don't let this stall" bucket.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAcceptedNotConverted(Builder $query): Builder
    {
        return $query->where('status', QuotationStatus::Accepted->value)
            ->whereNull('converted_order_id');
    }

    /**
     * Active (non-terminal) quotations whose expiry falls within the given
     * horizon, defaulting to the next 7 days.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpiringSoon(Builder $query, int $days = 7): Builder
    {
        return $query->open()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now()->startOfDay(), now()->addDays($days)->endOfDay()]);
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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    /**
     * Service jobs that were quoted through this quotation (Support's coverage-decision billing).
     *
     * @return HasMany<MaintenanceRecord, $this>
     */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    /** @return HasMany<QuotationResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(QuotationResponse::class)->orderByDesc('responded_at');
    }

    /**
     * The customer quote request this quotation was converted from, when
     * present — null for quotations authored directly by Sales.
     *
     * @return HasOne<CustomerQuotationRequest, $this>
     */
    public function customerQuotationRequest(): HasOne
    {
        return $this->hasOne(CustomerQuotationRequest::class, 'resulting_quotation_id');
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

        // The expiry date is valid through the end of that day, matching the expiry sweep, which
        // only expires a quotation once `expires_at` is strictly before today.
        return $this->status === QuotationStatus::Sent
            && $this->expires_at?->copy()->endOfDay()->isPast() === true;
    }

    /**
     * Support-origin quotations are billed by Support itself (the maintenance record invoices the
     * quotation, and its service lines have no product variant), so converting one to a sales
     * order would bill the same work twice or fail on the variant-less line.
     */
    public function isSupportOrigin(): bool
    {
        if ($this->maintenanceRecords()->exists()) {
            return true;
        }

        return $this->lines()->whereNull('product_variant_id')->exists();
    }

    public function isConvertibleToOrder(): bool
    {
        return $this->status === QuotationStatus::Accepted
            && $this->converted_order_id === null
            && ! $this->isSupportOrigin();
    }

    public function isFrozen(): bool
    {
        return $this->getRawOriginal('status') !== QuotationStatus::Draft->value;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('quotation-pdf')->useDisk('local');
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
