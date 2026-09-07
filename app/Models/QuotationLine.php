<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CarriesPriceProvenance;
use App\Services\Sales\Exceptions\QuotationImmutable;
use Database\Factories\QuotationLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced line within a {@see Quotation} (data-model.md §4).
 *
 * Refuses to update or delete once its parent quotation has been sent
 * (FR-023) — the line-level half of {@see Quotation::guardAgainstFrozenWrite()}.
 */
#[Fillable([
    'product_variant_id', 'unit_id', 'description', 'quantity', 'transaction_quantity',
    'transaction_unit_id', 'conversion_factor_snapshot', 'base_quantity', 'unit_price',
    'tax_amount', 'line_total', 'resolved_price_source', 'resolved_price_tier_id',
    'price_floor_override_id', 'list_price_minor', 'floor_price_minor', 'sort_order',
])]
final class QuotationLine extends Model
{
    use CarriesPriceProvenance;

    /** @use HasFactory<QuotationLineFactory> */
    use HasFactory;

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

    /** @return BelongsTo<Quotation, $this> */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
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

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            ...$this->priceProvenanceCasts(),
            'quantity' => 'decimal:6',
            'transaction_quantity' => 'decimal:6',
            'conversion_factor_snapshot' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    private function guardAgainstFrozenParent(): void
    {
        $quotation = $this->quotation;

        if (! $quotation instanceof Quotation || ! $quotation->isFrozen()) {
            return;
        }

        throw QuotationImmutable::forQuotation((string) $quotation->quotation_number);
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $line): void {
            $line->guardAgainstFrozenParent();
        });

        self::deleting(function (self $line): void {
            $line->guardAgainstFrozenParent();
        });
    }
}
