<?php

declare(strict_types=1);

use App\Enums\PricingTierDiscountType;
use App\Enums\ProductStatus;
use App\Enums\ResolvedPriceSource;
use App\Models\CustomerPricingTier;
use App\Models\CustomerProfile;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Services\Inventory\PriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves specific then lowest product-scoped then general pricing without stacking', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 120, 'min_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $general = PricingTier::factory()->create(['discount_value' => 5]);
    CustomerPricingTier::factory()->create(['customer_user_id' => $profile->user_id, 'pricing_tier_id' => $general->id]);
    $fixed = PricingTier::factory()->fixed()->create(['discount_value' => 15]);
    $percentage = PricingTier::factory()->productScoped()->create(['discount_value' => 10]);

    foreach ([$fixed, $percentage] as $tier) {
        $tier->products()->attach($variant->product_id);
        CustomerPricingTier::factory()->create(['customer_user_id' => $profile->user_id, 'pricing_tier_id' => $tier->id]);
    }

    $productPrice = app(PriceResolver::class)->resolve($variant, $profile->user);

    expect($productPrice->amount)->toBe(105.0)
        ->and($productPrice->source)->toBe(ResolvedPriceSource::ProductScopedTier)
        ->and($productPrice->pricingTier?->id)->toBe($fixed->id)
        ->and($productPrice->discountType)->toBe(PricingTierDiscountType::Fixed)
        ->and($productPrice->discountValue)->toBe(15.0)
        ->and($productPrice->discountAmount)->toBe(15.0)
        ->and($productPrice->minimumPrice)->toBe(100.0)
        ->and($productPrice->isBelowFloor)->toBeFalse();

    $specific = PricingTier::factory()->customerSpecific()->create([
        'customer_user_id' => $profile->user_id,
        'discount_value' => 20,
    ]);
    $specificPrice = app(PriceResolver::class)->resolve($variant, $profile->user);

    expect($specificPrice->amount)->toBe(96.0)
        ->and($specificPrice->source)->toBe(ResolvedPriceSource::CustomerSpecificTier)
        ->and($specificPrice->pricingTier?->id)->toBe($specific->id);
});

it('uses tier id as the tie breaker for equal product-scoped amounts', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 120, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $first = PricingTier::factory()->fixed()->create(['discount_value' => 12]);
    $second = PricingTier::factory()->productScoped()->create(['discount_value' => 10]);

    foreach ([$first, $second] as $tier) {
        $tier->products()->attach($variant->product_id);
        CustomerPricingTier::factory()->create(['customer_user_id' => $profile->user_id, 'pricing_tier_id' => $tier->id]);
    }

    expect(app(PriceResolver::class)->resolve($variant, $profile->user)->pricingTier?->id)->toBe($first->id);
});

it('falls back to base for inactive customers products variants and out-of-date tiers', function (): void {
    $profile = CustomerProfile::factory()->create(['is_active' => false]);
    $variant = ProductVariant::factory()->create(['base_price' => 120, 'status' => ProductStatus::Active]);
    $tier = PricingTier::factory()->productScoped()->create(['valid_until' => today()->subDay()]);
    $tier->products()->attach($variant->product_id);
    CustomerPricingTier::factory()->create(['customer_user_id' => $profile->user_id, 'pricing_tier_id' => $tier->id]);

    $price = app(PriceResolver::class)->resolve($variant, $profile->user);

    expect($price->amount)->toBe(120.0)
        ->and($price->source)->toBe(ResolvedPriceSource::Base)
        ->and($price->pricingTier)->toBeNull();
});

it('skips invalid product-scoped candidates and inactive variants', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $invalidTier = PricingTier::factory()->fixed()->create(['discount_value' => 100]);
    $invalidTier->products()->attach($variant->product_id);
    CustomerPricingTier::factory()->create(['customer_user_id' => $profile->user_id, 'pricing_tier_id' => $invalidTier->id]);

    expect(app(PriceResolver::class)->resolve($variant, $profile->user)->source)->toBe(ResolvedPriceSource::Base);

    $variant->update(['is_active' => false]);

    expect(app(PriceResolver::class)->resolve($variant->refresh(), $profile->user)->source)->toBe(ResolvedPriceSource::Base);
});
