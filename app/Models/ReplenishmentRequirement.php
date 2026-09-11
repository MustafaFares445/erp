<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReplenishmentRequirementStatus;
use App\Models\Concerns\TracksBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'warehouse_replenishment_policy_id',
    'warehouse_id',
    'product_variant_id',
    'required_base_quantity',
    'covered_base_quantity',
    'fulfilled_base_quantity',
    'status',
    'triggered_at',
    'resolved_at',
])]
final class ReplenishmentRequirement extends Model
{
    use TracksBlameable;

    protected $attributes = [
        'covered_base_quantity' => 0,
        'fulfilled_base_quantity' => 0,
        'status' => 'open',
    ];

    #[\Override]
    public function casts(): array
    {
        return [
            'required_base_quantity' => 'decimal:6',
            'covered_base_quantity' => 'decimal:6',
            'fulfilled_base_quantity' => 'decimal:6',
            'status' => ReplenishmentRequirementStatus::class,
            'triggered_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WarehouseReplenishmentPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(WarehouseReplenishmentPolicy::class, 'warehouse_replenishment_policy_id');
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

    /** @return HasMany<ReplenishmentCoverage, $this> */
    public function coverages(): HasMany
    {
        return $this->hasMany(ReplenishmentCoverage::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ReplenishmentRequirementStatus::Open->value,
            ReplenishmentRequirementStatus::PartiallyCovered->value,
            ReplenishmentRequirementStatus::Covered->value,
        ]);
    }

    public function remainingUncoveredQuantity(): float
    {
        return max(
            0.0,
            round((float) $this->required_base_quantity - (float) $this->covered_base_quantity, 6),
        );
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            ReplenishmentRequirementStatus::Fulfilled,
            ReplenishmentRequirementStatus::Cancelled,
        ], true);
    }
}
