<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Services\Inventory\PricingTierDiscountCalculator;
use App\Services\Inventory\ProductPricingService;

/**
 * The line and document arithmetic shared by quotations, invoices, and credit
 * notes (FR-017, FR-018, data-model.md §4).
 *
 * A single implementation rather than one per document, because the formula
 * — `line_total = round(quantity * unit_price, 2) + tax_amount`, with tax
 * defaulted from a percentage and overridable per line — is identical across
 * all three. Plain `round()` on native floats, matching the convention
 * {@see PricingTierDiscountCalculator} and
 * {@see ProductPricingService} already established
 * for money in this codebase, rather than introducing bcmath as a second one.
 *
 * Order lines use the same arithmetic but are computed by
 * {@see QuotationConversionService}, which copies rather
 * than recomputes (data-model.md §6).
 */
final readonly class LineTotalCalculator
{
    /**
     * The default tax for a line before any manual override, per FR-017.
     */
    public function defaultTax(float $quantity, float $unitPrice, float $taxPercent): float
    {
        return round($quantity * $unitPrice * ($taxPercent / 100), 2);
    }

    public function lineTotal(float $quantity, float $unitPrice, float $taxAmount): float
    {
        return round($quantity * $unitPrice, 2) + $taxAmount;
    }

    /**
     * @param  list<array{subtotal: float, tax_amount: float, line_total: float}>  $lines
     * @return array{subtotal: float, tax_total: float, grand_total: float}
     */
    public function documentTotals(array $lines): array
    {
        return [
            'subtotal' => round(array_sum(array_column($lines, 'subtotal')), 2),
            'tax_total' => round(array_sum(array_column($lines, 'tax_amount')), 2),
            'grand_total' => round(array_sum(array_column($lines, 'line_total')), 2),
        ];
    }
}
