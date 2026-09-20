<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerQuotationRequestStatus;
use App\Models\Concerns\TracksBlameable;
use App\Services\Crm\CustomerQuotationRequestService;
use App\Services\Sales\QuotationService;
use Database\Factories\CustomerQuotationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's pre-quotation request — the primary "Add to Quote Request
 * Cart" action from the future customer app. It never stores a
 * client-trusted selling price; only
 * {@see CustomerQuotationRequestService::convertToQuotation()}
 * resolves a real price, through the existing {@see QuotationService}.
 */
#[Fillable([
    'request_number', 'customer_id', 'customer_delivery_address_id', 'notes', 'status',
    'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note', 'resulting_quotation_id', 'source_channel',
])]
final class CustomerQuotationRequest extends Model
{
    /** @use HasFactory<CustomerQuotationRequestFactory> */
    use HasFactory;

    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => CustomerQuotationRequestStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_id');
    }

    /** @return BelongsTo<CustomerDeliveryAddress, $this> */
    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerDeliveryAddress::class, 'customer_delivery_address_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<Quotation, $this> */
    public function resultingQuotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'resulting_quotation_id');
    }

    /** @return HasMany<CustomerQuotationRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CustomerQuotationRequestLine::class)->orderBy('sort_order');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
