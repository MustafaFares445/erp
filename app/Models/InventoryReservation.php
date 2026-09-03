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
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
    'released_by',
    'release_reason',
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

    /** @return BelongsTo<User, $this> */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * Current canonical reservation source. The explicit source_type guard in
     * resolvedSourceDocument() prevents a future source kind from being
     * accidentally interpreted as an inventory operation merely because ids
     * overlap.
     *
     * @return BelongsTo<InventoryOperation, $this>
     */
    public function sourceOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class, 'source_id');
    }

    /** @return HasMany<InventoryReservationAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryReservationAllocation::class);
    }

    /** @return MorphMany<AuditLog, $this> */
    public function activities(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public function resolvedSourceDocument(): ?Model
    {
        if ($this->source_type !== 'inventory_operation') {
            return null;
        }

        $operation = $this->relationLoaded('sourceOperation')
            ? $this->sourceOperation
            : $this->sourceOperation()->with('sourceDocument')->first();

        if (! $operation instanceof InventoryOperation) {
            return null;
        }

        if (! $operation->relationLoaded('sourceDocument')) {
            $operation->load('sourceDocument');
        }

        return $operation->sourceDocument instanceof Model
            ? $operation->sourceDocument
            : $operation;
    }

    public function isActive(): bool
    {
        return $this->status === ReservationStatus::Active;
    }
}
