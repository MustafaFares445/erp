<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\PricingTierDiscountType;
use DomainException;

final class PricingTierDiscountCalculator
{
    /** @return array{amount: float, discount_amount: float} */
    public function calculate(float $basePrice, PricingTierDiscountType $discountType, float $discountValue): array
    {
        if ($basePrice <= 0) {
            throw new DomainException('The base price must be greater than zero.');
        }

        if ($discountType === PricingTierDiscountType::Percentage && ($discountValue < 0 || $discountValue > 100)) {
            throw new DomainException('A percentage discount must be between 0 and 100.');
        }

        if ($discountType === PricingTierDiscountType::Fixed && $discountValue <= 0) {
            throw new DomainException('A fixed discount must be greater than zero.');
        }

        $discountAmount = $discountType === PricingTierDiscountType::Percentage
            ? $basePrice * ($discountValue / 100)
            : $discountValue;
        $amount = round($basePrice - $discountAmount, 2);

        if ($amount <= 0) {
            throw new DomainException('A pricing-tier discount must leave a positive price.');
        }

        return ['amount' => $amount, 'discount_amount' => round($basePrice - $amount, 2)];
    }
}
