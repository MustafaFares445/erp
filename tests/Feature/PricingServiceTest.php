<?php

declare(strict_types=1);

use App\Enums\PricingTierType;
use App\Enums\ProductStatus;
use App\Enums\ResolvedPriceSource;
use App\Models\CustomerPricingTier;
use App\Models\CustomerProfile;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Services\Inventory\PriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('falls back from product-scoped pricing to the assigned general tier then base', function (): void {
    $profile = CustomerProfile::factory()->create();
    $otherProfile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $general = PricingTier::factory()->create(['discount_value' => 15, 'tier_type' => PricingTierType::General]);
    CustomerPricingTier::factory()->create(['customer_user_id' => $profile->user_id, 'pricing_tier_id' => $general->id]);

    $generalPrice = app(PriceResolver::class)->resolve($variant, $profile->user);
    $basePrice = app(PriceResolver::class)->resolve($variant, $otherProfile->user);

    expect($generalPrice->amount)->toBe(85.0)
        ->and($generalPrice->source)->toBe(ResolvedPriceSource::GeneralTier)
        ->and($basePrice->amount)->toBe(100.0)
        ->and($basePrice->source)->toBe(ResolvedPriceSource::Base);
});

it('uses the base price without a customer and enforces the minimum floor', function (): void {
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 80]);
    $resolver = app(PriceResolver::class);

    expect($resolver->resolve($variant)->amount)->toBe(100.0);
    $resolver->assertAtOrAboveFloor($variant, 80);

    expect(fn () => $resolver->assertAtOrAboveFloor($variant, 79.99))->toThrow(DomainException::class);
});
