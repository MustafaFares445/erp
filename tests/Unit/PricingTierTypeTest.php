<?php

declare(strict_types=1);

use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\PricingTierVisibility;

it('defines the unified pricing tier catalogues without subscription values', function (): void {
    expect(array_column(PricingTierType::cases(), 'value'))->toBe(['general', 'customer_specific', 'product_scoped'])
        ->and(array_column(PricingTierDiscountType::cases(), 'value'))->toBe(['percentage', 'fixed'])
        ->and(array_column(PricingTierVisibility::cases(), 'value'))->toBe(['public', 'restricted'])
        ->and(implode('|', [
            ...array_column(PricingTierType::cases(), 'value'),
            ...array_column(PricingTierDiscountType::cases(), 'value'),
            ...array_column(PricingTierVisibility::cases(), 'value'),
        ]))->not->toContain('subscription');
});
