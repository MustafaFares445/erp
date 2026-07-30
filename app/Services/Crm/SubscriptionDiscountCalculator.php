<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\ProductSubscriptionDiscountType;
use DomainException;

final class SubscriptionDiscountCalculator
{
    /** @return array{amount: float, discount_amount: float} */
    public function calculate(
        float $basePrice,
        ProductSubscriptionDiscountType $discountType,
        float $discountValue,
    ): array {
        if ($basePrice <= 0) {
            throw new DomainException('The base price must be greater than zero.');
        }

        if ($discountValue <= 0) {
            throw new DomainException('The discount value must be greater than zero.');
        }

        if ($discountType === ProductSubscriptionDiscountType::Percentage && $discountValue > 100) {
            throw new DomainException('A percentage discount cannot exceed 100.');
        }

        $discountAmount = $discountType === ProductSubscriptionDiscountType::Percentage
            ? $basePrice * ($discountValue / 100)
            : $discountValue;
        $amount = round($basePrice - $discountAmount, 2);

        if ($amount <= 0) {
            throw new DomainException('A subscription discount must leave a positive price.');
        }

        return [
            'amount' => $amount,
            'discount_amount' => round($basePrice - $amount, 2),
        ];
    }
}
