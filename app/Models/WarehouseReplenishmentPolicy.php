<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'warehouse_id',
    'product_variant_id',
    'min_quantity',
    'max_quantity',
    'is_active',
])]
final class WarehouseReplenishmentPolicy extends Model
{
    use TracksBlameable;

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        self::saving(function (self $policy): void {
            $minimum = (float) $policy->min_quantity;
            $maximum = (float) $policy->max_quantity;

            if ($minimum < 0) {
                throw new DomainException('Replenishment minimum quantity cannot be negative.');
            }

            if ($maximum <= $minimum) {
                throw new DomainException('Replenishment maximum quantity must be greater than the minimum quantity.');
            }
        });
    }

    #[\Override]
    public function casts(): array
    {
        return [
            'min_quantity' => 'decimal:6',
            'max_quantity' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<ReplenishmentRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(ReplenishmentRequirement::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
