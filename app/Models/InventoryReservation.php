<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\InventoryReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_variant_id',
    'warehouse_id',
    'source_type',
    'source_id',
    'source_line_type',
    'source_line_id',
    'base_quantity',
    'status',
    'expires_at',
    'consumed_at',
    'released_at',
    'legacy_stock_reservation_id',
])]
final class InventoryReservation extends Model
{
    /** @use HasFactory<InventoryReservationFactory> */
    use HasFactory;

    use TracksBlameable;

    protected $attributes = ['status' => 'active'];

    #[\Override]
    public function casts(): array
    {
        return [
            'base_quantity' => 'decimal:6',
            'status' => ReservationStatus::class,
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<InventoryReservationAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryReservationAllocation::class);
    }

    public function isActive(): bool
    {
        return $this->status === ReservationStatus::Active;
    }
}
