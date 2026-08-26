<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Concerns\TracksBlameable;
use App\Observers\ProductObserver;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['name', 'name_ar', 'description', 'status', 'product_type', 'category_id', 'brand_id', 'is_active'])]
#[ObservedBy(ProductObserver::class)]
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
            'product_type' => ProductType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    #[Scope]
    protected function ofType(Builder $query, ProductType $type): Builder
    {
        return $query->where('product_type', $type->value);
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

    /** @return BelongsToMany<PricingTier, $this> */
    public function pricingTiers(): BelongsToMany
    {
        return $this->belongsToMany(PricingTier::class, 'pricing_tier_products')->withTimestamps();
    }

    /** @return BelongsToMany<Unit, $this> */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'product_units')->withPivot('is_default')->withTimestamps();
    }

    /**
     * Replaces this product's allowed units. Exactly one ends up flagged default — the one
     * named, or the first of the given units if none was named or the named one isn't among
     * them.
     *
     * @param  array<array-key, mixed>  $unitIds
     */
    public function syncUnits(array $unitIds, int|string|null $defaultUnitId = null): void
    {
        $unitIds = array_values(array_unique(array_map(
            static fn (mixed $unitId): int => (int) $unitId,
            array_filter($unitIds, 'is_numeric'),
        )));

        if (! in_array((int) $defaultUnitId, $unitIds, true)) {
            $defaultUnitId = $unitIds[0] ?? null;
        }

        $this->units()->sync(collect($unitIds)
            ->mapWithKeys(static fn (int $unitId): array => [$unitId => ['is_default' => $unitId === (int) $defaultUnitId]])
            ->all());
    }

    public function addAllowedUnit(Unit $unit): void
    {
        $unitId = $unit->getKey();

        if (! is_int($unitId)) {
            throw new \LogicException('A product unit must have an integer ID.');
        }

        if ($this->units()->whereKey($unitId)->exists()) {
            return;
        }

        $this->units()->attach($unitId, ['is_default' => ! $this->units()->exists()]);
    }

    /** @return HasManyThrough<SupplierProductReference, ProductVariant, $this> */
    public function supplierProductReferences(): HasManyThrough
    {
        return $this->hasManyThrough(SupplierProductReference::class, ProductVariant::class);
    }

    /** @return HasMany<SupplierProductSupport, $this> */
    public function supplierProductSupports(): HasMany
    {
        return $this->hasMany(SupplierProductSupport::class);
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

    /**
     * Whether anything has physically happened to this product yet.
     *
     * Gates {@see ProductType} changes: a type fixes how its variants are tracked, so
     * switching it after goods have moved would orphan the lots or serialized units the old
     * type created. Consistent with the existing rule that records used in past transactions
     * are never removed — the answer is a new product, not a re-typed one.
     */
    public function hasStockHistory(): bool
    {
        if ($this->movements()->exists()) {
            return true;
        }

        return $this->stocks()->where('on_hand_quantity', '>', 0)->exists();
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
