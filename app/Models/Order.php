<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderPaymentStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'order_number', 'customer_id', 'customer_delivery_address_id', 'status', 'pending_reason', 'scheduled_at',
    'delivery_type', 'responsible_id', 'destination_address_snapshot', 'notes',
    'quotation_id', 'payment_term_id', 'subtotal', 'tax_total', 'grand_total', 'payment_status',
])]
final class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use TracksBlameable;

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<CustomerDeliveryAddress, $this> */
    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerDeliveryAddress::class, 'customer_delivery_address_id');
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /** @return BelongsTo<Quotation, $this> */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** @return BelongsTo<PaymentTerm, $this> */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    /** @return MorphMany<InventoryOperation, $this> */
    public function deliveries(): MorphMany
    {
        return $this->morphMany(InventoryOperation::class, 'source_document')
            ->where('operation_type', 'delivery');
    }

    /** @return HasMany<Shipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * Supplier confirmations recorded against this customer order.
     *
     * The ERD's sanctioned purchasing flow: an order that cannot be filled from
     * stock waits on a supplier's answer, and that answer is recorded here
     * rather than on a purchase order (spec 017 FR-028).
     *
     * @return MorphMany<SupplierConfirmation, $this>
     */
    public function confirmations(): MorphMany
    {
        return $this->morphMany(SupplierConfirmation::class, 'confirmable');
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'destination_address_snapshot' => 'array',
            'payment_status' => OrderPaymentStatus::class,
        ];
    }
}
