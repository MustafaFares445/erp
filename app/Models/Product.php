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
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['name', 'name_ar', 'description', 'status', 'category_id', 'brand_id', 'is_active'])]
final class Product extends Model implements HasMedia
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use InteractsWithMedia;
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

    /** @return HasManyThrough<SupplierProductReference, ProductVariant, $this> */
    public function supplierProductReferences(): HasManyThrough
    {
        return $this->hasManyThrough(SupplierProductReference::class, ProductVariant::class);
    }

    /** @return HasManyThrough<InventoryStock, ProductVariant, $this> */
    public function stocks(): HasManyThrough
    {
        return $this->hasManyThrough(InventoryStock::class, ProductVariant::class);
    }

    /** @return HasManyThrough<InventoryMovement, ProductVariant, $this> */
    public function movements(): HasManyThrough
    {
        return $this->hasManyThrough(InventoryMovement::class, ProductVariant::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->nonQueued()
            ->width(300)
            ->height(300);
    }

    public function mainImageUrl(): ?string
    {
        $url = $this->getFirstMediaUrl('images', 'thumb');

        return $url === '' ? null : $url;
    }
}
