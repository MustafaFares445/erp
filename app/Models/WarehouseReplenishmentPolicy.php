<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use App\Services\Inventory\ReplenishmentRequirementService;
use Closure;
use Database\Factories\WarehouseReplenishmentPolicyFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

#[Fillable([
    'warehouse_id',
    'product_variant_id',
    'min_quantity',
    'max_quantity',
    'is_active',
])]
final class WarehouseReplenishmentPolicy extends Model
{
    /** @use HasFactory<WarehouseReplenishmentPolicyFactory> */
    use HasFactory;

    use TracksBlameable;

    /** @var array<string, mixed> */
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

        self::saved(static function (self $policy): void {
            app(ReplenishmentRequirementService::class)->sync($policy);
        });
    }

    /** @return array<string, string> */
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

    public function isBreachedBy(InventoryStock $stock): bool
    {
        return $this->is_active && $stock->saleableAvailableQuantity() <= (float) $this->min_quantity;
    }

    public static function breachedSubquery(): Closure
    {
        return function (QueryBuilder $policies): void {
            $policies->from('warehouse_replenishment_policies')
                ->whereColumn('warehouse_replenishment_policies.warehouse_id', 'inventory_stocks.warehouse_id')
                ->whereColumn('warehouse_replenishment_policies.product_variant_id', 'inventory_stocks.product_variant_id')
                ->where('warehouse_replenishment_policies.is_active', true)
                ->whereColumn('inventory_stocks.available_quantity', '<=', 'warehouse_replenishment_policies.min_quantity');
        };
    }
}
