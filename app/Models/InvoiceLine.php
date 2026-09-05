<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CarriesPriceProvenance;
use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_variant_id', 'order_line_id', 'description', 'quantity', 'unit_price',
    'tax_amount', 'line_total', 'sort_order', 'resolved_price_source',
    'resolved_price_tier_id', 'price_floor_override_id', 'list_price_minor', 'floor_price_minor',
])]
final class InvoiceLine extends Model
{
    use CarriesPriceProvenance;

    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<OrderLine, $this> */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
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

    /** @return HasMany<CreditNoteLine, $this> */
    public function creditNoteLines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            ...$this->priceProvenanceCasts(),
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $line): void {
            if ($line->invoice()->whereNotNull('issued_at')->exists()) {
                throw new \DomainException('An issued invoice line is immutable.');
            }
        });

        self::deleting(function (self $line): void {
            if ($line->invoice()->whereNotNull('issued_at')->exists()) {
                throw new \DomainException('An issued invoice line cannot be deleted.');
            }
        });
    }
}
