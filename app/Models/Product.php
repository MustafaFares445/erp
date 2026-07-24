<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'name_ar', 'description', 'status', 'category_id', 'brand_id', 'is_active'])]
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
