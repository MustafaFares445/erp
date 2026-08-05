<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Concerns\TracksBlameable;
use App\Observers\ProductVariantObserver;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property int $product_id
 * @property string $sku
 */
#[Fillable(['product_id', 'sku', 'name', 'name_ar', 'barcode', 'unit_id', 'track_serials', 'track_expiry', 'net_weight', 'weight_unit_id', 'cost_price', 'base_price', 'min_price', 'markup_percent', 'status', 'is_active'])]
#[ObservedBy(ProductVariantObserver::class)]
final class ProductVariant extends Model implements HasMedia
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return [
            'track_serials' => 'boolean',
            'track_expiry' => 'boolean',
            'net_weight' => 'decimal:3',
            'is_active' => 'boolean',
            'cost_price' => 'decimal:2',
            'base_price' => 'decimal:2',
            'min_price' => 'decimal:2',
            'markup_percent' => 'decimal:2',
            'status' => ProductStatus::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The unit the {@see self::$net_weight} is expressed in — kilograms, tonnes, and so on.
     * Only populated for {@see ProductType::Grain} variants.
     *
     * @return BelongsTo<Unit, $this>
     */
    public function weightUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'weight_unit_id');
    }

    /** @return HasMany<InventoryStock, $this> */
    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** @return HasMany<SupplierProductReference, $this> */
    public function supplierReferences(): HasMany
    {
        return $this->hasMany(SupplierProductReference::class);
    }

    /** @return HasMany<SerializedInventoryUnit, $this> */
    public function serializedUnits(): HasMany
    {
        return $this->hasMany(SerializedInventoryUnit::class);
    }

    /** @return HasMany<InventoryLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }

    /** @return HasMany<PriceHistory, $this> */
    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    /** @return HasMany<ProductVariantAttributeValue, $this> */
    public function attributeAssignments(): HasMany
    {
        return $this->hasMany(ProductVariantAttributeValue::class);
    }

    /**
     * The authoritative type for this variant, taken from its parent product.
     *
     * Callers iterating many variants should eager-load `product` to avoid an N+1; the
     * inventory services and Filament tables that use this all do.
     */
    public function productType(): ?ProductType
    {
        return $this->product?->product_type;
    }

    /**
     * The total weight this quantity represents, or null when the variant carries no net
     * weight — the derived figure grain reporting and stock valuation are built on.
     */
    public function weightFor(float $quantity): ?float
    {
        $netWeight = $this->net_weight;

        return $netWeight === null ? null : round($quantity * (float) $netWeight, 3);
    }

    /**
     * The symbol a weight figure should be shown in, ready to append to a formatted number.
     * Empty when the variant carries no weight unit, so a display never invents one.
     */
    public function weightSuffix(): string
    {
        $symbol = $this->weightUnit?->symbol;

        return $symbol === null || $symbol === '' ? '' : ' '.$symbol;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    #[Scope]
    protected function ofProductType(Builder $query, ProductType $type): Builder
    {
        return $query->whereHas('product', fn (Builder $products): Builder => $products->where('product_type', $type->value));
    }

    public function isOperational(): bool
    {
        $product = $this->product;

        return $this->is_active
            && $this->status->isOperational()
            && $product instanceof Product
            && $product->is_active
            && $product->status->isOperational();
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

        if ($url !== '') {
            return $url;
        }

        return $this->product?->mainImageUrl();
    }
}
