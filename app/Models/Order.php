<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'order_number', 'customer_id', 'customer_delivery_address_id', 'status', 'scheduled_at',
    'delivery_type', 'responsible_id', 'destination_address_snapshot', 'notes',
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

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'destination_address_snapshot' => 'array',
        ];
    }
}
