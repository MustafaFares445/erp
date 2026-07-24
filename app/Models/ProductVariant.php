<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['product_id', 'sku', 'name', 'name_ar', 'barcode', 'unit_id', 'track_serials', 'track_expiry', 'cost_price', 'base_price', 'min_price', 'markup_percent', 'status', 'is_active'])]
final class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return [
            'track_serials' => 'boolean',
            'track_expiry' => 'boolean',
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

    public function isOperational(): bool
    {
        $product = $this->product;

        return $this->is_active
            && $this->status->isOperational()
            && $product instanceof Product
            && $product->is_active
            && $product->status->isOperational();
    }
}
