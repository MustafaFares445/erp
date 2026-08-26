<?php

declare(strict_types=1);

namespace App\Models;

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
    'product_variant_id', 'description', 'quantity', 'unit_price',
    'tax_amount', 'line_total', 'resolved_price_source', 'sort_order',
])]
final class QuotationLine extends Model
{
    /** @use HasFactory<QuotationLineFactory> */
    use HasFactory;

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

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return ['quantity' => 'decimal:3'];
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
