<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\TrackingMode;
use App\Enums\WarrantyDurationUnit;
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
 * @property string $name
 * @property int $unit_id
 */
#[Fillable(['product_id', 'sku', 'name', 'name_ar', 'barcode', 'manufacturer_part_number', 'gtin', 'udi_di', 'device_identifier', 'unit_id', 'tracking_mode', 'tracks_expiration', 'track_serials', 'track_expiry', 'track_batches', 'serviceable', 'warranty_enabled', 'udi_enabled', 'net_weight', 'weight_unit_id', 'cost_price', 'base_price', 'min_price', 'markup_percent', 'warranty_duration_value', 'warranty_duration_unit', 'warranty_policy_id', 'status', 'is_active'])]
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
            'tracking_mode' => TrackingMode::class,
            'tracks_expiration' => 'boolean',
            'track_serials' => 'boolean',
            'track_expiry' => 'boolean',
            'track_batches' => 'boolean',
            'serviceable' => 'boolean',
            'warranty_enabled' => 'boolean',
            'udi_enabled' => 'boolean',
            'net_weight' => 'decimal:3',
            'is_active' => 'boolean',
            'cost_price' => 'decimal:2',
            'base_price' => 'decimal:2',
            'min_price' => 'decimal:2',
            'markup_percent' => 'decimal:2',
            'warranty_duration_value' => 'integer',
            'warranty_duration_unit' => WarrantyDurationUnit::class,
            'status' => ProductStatus::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<WarrantyPolicy, $this> */
    public function warrantyPolicy(): BelongsTo
    {
        return $this->belongsTo(WarrantyPolicy::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<ProductVariantUnit, $this> */
    public function variantUnits(): HasMany
    {
        return $this->hasMany(ProductVariantUnit::class);
    }

    /** @return HasMany<ProductVariantUnit, $this> */
    public function activeVariantUnits(): HasMany
    {
        return $this->variantUnits()->where('is_active', true);
    }

    /** @return BelongsTo<Unit, $this> */
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

    public function hasStockHistory(): bool
    {
        if ($this->movements()->exists()) {
            return true;
        }

        return $this->stocks()->where('on_hand_quantity', '>', 0)->exists();
    }

    /** @return HasMany<SupplierProductReference, $this> */
    public function supplierReferences(): HasMany
    {
        return $this->hasMany(SupplierProductReference::class);
    }

    /** @return HasMany<SupplierProductSupport, $this> */
    public function supplierProductSupports(): HasMany
    {
        return $this->hasMany(SupplierProductSupport::class);
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

    public function productType(): ?ProductType
    {
        return $this->product?->product_type;
    }

    public function trackingMode(): TrackingMode
    {
        $hasLegacySerial = array_key_exists('track_serials', $this->attributes);
        $hasLegacyBatch = array_key_exists('track_batches', $this->attributes);

        if ($hasLegacySerial && $this->track_serials === true) {
            return TrackingMode::Serial;
        }

        if ($hasLegacyBatch && $this->track_batches === true) {
            return TrackingMode::Lot;
        }

        // During the migration window legacy writers can still update both projection
        // columns directly. When both are loaded and explicitly false, preserve that
        // established behaviour instead of letting an older tracking_mode value win.
        if ($hasLegacySerial && $hasLegacyBatch) {
            return TrackingMode::None;
        }

        if ($this->tracking_mode instanceof TrackingMode) {
            return $this->tracking_mode;
        }

        $type = $this->productType();

        if ($type?->tracksSerials() === true) {
            return TrackingMode::Serial;
        }

        return $type?->tracksBatches() === true ? TrackingMode::Lot : TrackingMode::None;
    }

    public function tracksSerialsConfigured(): bool
    {
        return $this->trackingMode() === TrackingMode::Serial;
    }

    public function tracksLotsConfigured(): bool
    {
        return $this->trackingMode() === TrackingMode::Lot;
    }

    public function tracksExpirationConfigured(): bool
    {
        return $this->tracks_expiration ?? $this->track_expiry;
    }

    public function weightFor(float $quantity): ?float
    {
        $netWeight = $this->net_weight;

        return $netWeight === null ? null : round($quantity * (float) $netWeight, 3);
    }

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
