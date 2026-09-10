<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\PriceListItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class PricingTierService
{
    private const ONE_HUNDRED = '100';

    public function __construct(
        private readonly PricingTierDiscountCalculator $discountCalculator,
    ) {}

    public function resolveUnitPrice(
        PriceListItem $priceListItem,
        string $quantity,
        string $fallbackUnitPrice
    ): string {
        $basePrice = $priceListItem->unit_price ?? $fallbackUnitPrice;

        $tier = $priceListItem->pricingTiers()
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($query) use ($quantity) {
                $query->whereNull('max_quantity')
                    ->orWhere('max_quantity', '>=', $quantity);
            })
            ->orderByDesc('min_quantity')
            ->first();

        if (! $tier) {
            return BigDecimal::of((string) $basePrice)->toScale(2, RoundingMode::HALF_UP)->__toString();
        }

        if ($tier->unit_price !== null) {
            return BigDecimal::of((string) $tier->unit_price)->toScale(2, RoundingMode::HALF_UP)->__toString();
        }

        if ($tier->discount_percentage !== null) {
            return BigDecimal::of((string) $basePrice)
                ->multipliedBy(
                    BigDecimal::of(self::ONE_HUNDRED)
                        ->minus((string) $tier->discount_percentage)
                )
                ->dividedBy(self::ONE_HUNDRED, 6, RoundingMode::HALF_UP)
                ->toScale(2, RoundingMode::HALF_UP)
                ->__toString();
        }

        return BigDecimal::of((string) $basePrice)->toScale(2, RoundingMode::HALF_UP)->__toString();
    }

    /**
     * @return array{discount_percent:string, discount_amount:string, line_subtotal:string}
     */
    public function calculateDiscounts(
        string $unitPrice,
        string $quantity,
        string $discountPercentage
    ): array {
        return $this->discountCalculator->calculate($unitPrice, $quantity, $discountPercentage);
    }
}
