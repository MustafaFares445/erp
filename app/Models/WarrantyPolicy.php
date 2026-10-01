<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyStartTrigger;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\WarrantyPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code',
    'name',
    'duration_value',
    'duration_unit',
    'start_trigger',
    'covers_parts',
    'covers_labour',
    'covers_travel',
    'covers_consumables',
    'covers_third_party',
    'transferable',
    'replacement_rule',
    'exclusions',
    'is_active',
])]
final class WarrantyPolicy extends Model
{
    /** @use HasFactory<WarrantyPolicyFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'duration_value' => 'integer',
            'duration_unit' => WarrantyDurationUnit::class,
            'start_trigger' => WarrantyStartTrigger::class,
            'covers_parts' => 'boolean',
            'covers_labour' => 'boolean',
            'covers_travel' => 'boolean',
            'covers_consumables' => 'boolean',
            'covers_third_party' => 'boolean',
            'transferable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<WarrantyEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(WarrantyEntitlement::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function productVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
