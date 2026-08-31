<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use App\Observers\UnitObserver;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'name_ar', 'symbol', 'family', 'precision', 'allows_decimal', 'is_active'])]
#[ObservedBy(UnitObserver::class)]
final class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return [
            'precision' => 'integer',
            'allows_decimal' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** @return HasMany<ProductVariantUnit, $this> */
    public function variantUnits(): HasMany
    {
        return $this->hasMany(ProductVariantUnit::class);
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_units')->withPivot('is_default')->withTimestamps();
    }
}
