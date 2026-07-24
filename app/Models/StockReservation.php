<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\StockReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_variant_id', 'warehouse_id', 'quantity', 'source_type', 'source_id', 'expires_at', 'status'])]
final class StockReservation extends Model
{
    /** @use HasFactory<StockReservationFactory> */
    use HasFactory;

    use TracksBlameable;

    protected $attributes = ['status' => 'active'];

    #[\Override]
    public function casts(): array
    {
        return ['quantity' => 'decimal:3', 'expires_at' => 'datetime', 'status' => ReservationStatus::class];
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

    public function isReleasable(): bool
    {
        return $this->status === ReservationStatus::Active && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
