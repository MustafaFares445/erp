<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReplenishmentCoverageSourceType;
use App\Enums\ReplenishmentCoverageStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'replenishment_requirement_id',
    'source_type',
    'source_id',
    'covered_base_quantity',
    'status',
])]
final class ReplenishmentCoverage extends Model
{
    protected $attributes = [
        'status' => 'active',
    ];

    #[\Override]
    public function casts(): array
    {
        return [
            'source_type' => ReplenishmentCoverageSourceType::class,
            'covered_base_quantity' => 'decimal:6',
            'status' => ReplenishmentCoverageStatus::class,
        ];
    }

    /** @return BelongsTo<ReplenishmentRequirement, $this> */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ReplenishmentRequirement::class, 'replenishment_requirement_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ReplenishmentCoverageStatus::Active->value);
    }
}
