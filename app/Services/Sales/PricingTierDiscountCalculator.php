<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class PricingTierDiscountCalculator
{
    /**
     * @return array{discount_percent:string, discount_amount:string, line_subtotal:string}
     */
    public function calculate(string $unitPrice, string $quantity, string $discountPercentage): array
    {
        $price = BigDecimal::of($unitPrice);
        $qty = BigDecimal::of($quantity);
        $discount = BigDecimal::of($discountPercentage);

        $lineSubtotal = $price->multipliedBy($qty)->toScale(2, RoundingMode::HALF_UP);
        $discountAmount = $lineSubtotal
            ->multipliedBy($discount)
            ->dividedBy(100, 6, RoundingMode::HALF_UP)
            ->toScale(2, RoundingMode::HALF_UP);

        return [
            'discount_percent' => $discount->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'discount_amount' => $discountAmount->__toString(),
            'line_subtotal' => $lineSubtotal->__toString(),
        ];
    }
}
