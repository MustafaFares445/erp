<?php

declare(strict_types=1);

use App\Enums\PricingTierDiscountType;
use App\Services\Inventory\PricingTierDiscountCalculator;

it('calculates percentage and fixed pricing-tier candidates with money rounding', function (
    PricingTierDiscountType $discountType,
    float $discountValue,
    float $amount,
): void {
    expect(app(PricingTierDiscountCalculator::class)->calculate(120, $discountType, $discountValue))
        ->toBe(['amount' => $amount, 'discount_amount' => 120 - $amount]);
})->with([
    'percentage' => [PricingTierDiscountType::Percentage, 10.0, 108.0],
    'fixed' => [PricingTierDiscountType::Fixed, 15.0, 105.0],
]);

it('rejects non-positive fixed pricing-tier candidates', function (): void {
    expect(fn (): array => app(PricingTierDiscountCalculator::class)->calculate(120, PricingTierDiscountType::Fixed, 120))
        ->toThrow(DomainException::class, 'positive price');
});

it('rejects invalid pricing-tier inputs', function (
    float $basePrice,
    PricingTierDiscountType $discountType,
    float $discountValue,
    string $message,
): void {
    expect(fn (): array => app(PricingTierDiscountCalculator::class)->calculate($basePrice, $discountType, $discountValue))
        ->toThrow(DomainException::class, $message);
})->with([
    'non-positive base price' => [0, PricingTierDiscountType::Percentage, 10, 'base price'],
    'invalid percentage' => [100, PricingTierDiscountType::Percentage, 101, 'between 0 and 100'],
    'non-positive fixed amount' => [100, PricingTierDiscountType::Fixed, 0, 'greater than zero'],
]);
