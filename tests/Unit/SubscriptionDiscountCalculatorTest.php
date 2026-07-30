<?php

declare(strict_types=1);

use App\Enums\ProductSubscriptionDiscountType;
use App\Services\Crm\SubscriptionDiscountCalculator;

it('calculates percentage and fixed discount candidates with money rounding', function (
    ProductSubscriptionDiscountType $discountType,
    float $discountValue,
    float $amount,
): void {
    expect(app(SubscriptionDiscountCalculator::class)->calculate(120, $discountType, $discountValue))
        ->toBe(['amount' => $amount, 'discount_amount' => 120 - $amount]);
})->with([
    'percentage' => [ProductSubscriptionDiscountType::Percentage, 10.0, 108.0],
    'fixed' => [ProductSubscriptionDiscountType::Fixed, 15.0, 105.0],
]);

it('rejects zero or negative subscription candidates', function (): void {
    expect(fn (): array => app(SubscriptionDiscountCalculator::class)->calculate(
        120,
        ProductSubscriptionDiscountType::Fixed,
        120,
    ))->toThrow(DomainException::class, 'positive price');
});
