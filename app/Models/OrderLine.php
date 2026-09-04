<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResolvedPriceSource;
use App\Models\Concerns\CarriesPriceProvenance;
use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_variant_id', 'quantity', 'unit_id', 'transaction_quantity', 'transaction_unit_id',
    'conversion_factor_snapshot', 'base_quantity', 'unit_price', 'tax_amount', 'line_total',
    'resolved_price_source', 'resolved_price_tier_id', 'price_floor_override_id',
    'list_price_minor', 'floor_price_minor',
])]
final class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;
    use CarriesPriceProvenance;

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function transactionUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'transaction_unit_id');
    }

    /** @return BelongsTo<PricingTier, $this> */
    public function resolvedPriceTier(): BelongsTo
    {
        return $this->belongsTo(PricingTier::class, 'resolved_price_tier_id');
    }

    /** @return BelongsTo<PriceFloorOverride, $this> */
    public function priceFloorOverride(): BelongsTo
    {
        return $this->belongsTo(PriceFloorOverride::class);
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return HasMany<SalesProcurementRequirement, $this> */
    public function procurementRequirements(): HasMany
    {
        return $this->hasMany(SalesProcurementRequirement::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'transaction_quantity' => 'decimal:6',
            'conversion_factor_snapshot' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'resolved_price_source' => ResolvedPriceSource::class,
            'resolved_price_tier_id' => 'integer',
            'price_floor_override_id' => 'integer',
            'list_price_minor' => 'integer',
            'floor_price_minor' => 'integer',
        ];
    }
}
