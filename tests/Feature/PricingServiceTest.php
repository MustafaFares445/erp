<?php

declare(strict_types=1);

use App\Models\CustomerPricingTier;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\PriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves customer-specific pricing before an assigned general tier', function (): void {
    $customer = User::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100]);
    $generalTier = PricingTier::factory()->create(['discount_percent' => 15]);
    CustomerPricingTier::factory()->for($customer, 'customer')->for($generalTier, 'pricingTier')->create();
    $specificTier = PricingTier::factory()->for($customer, 'customer')->create(['discount_percent' => 20]);

    $price = app(PriceResolver::class)->resolve($variant, $customer);

    expect($price->amount)->toBe(80.0)
        ->and($price->pricingTier?->getKey())->toBe($specificTier->getKey());
});

it('falls back to an assigned general tier then to the base price', function (): void {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100]);
    $tier = PricingTier::factory()->create(['discount_percent' => 15]);
    CustomerPricingTier::factory()->for($customer, 'customer')->for($tier, 'pricingTier')->create();

    expect(app(PriceResolver::class)->resolve($variant, $customer)->amount)->toBe(85.0)
        ->and(app(PriceResolver::class)->resolve($variant, $otherCustomer)->amount)->toBe(100.0);
});
