<?php

declare(strict_types=1);

use App\Enums\ProductSubscriptionDiscountType;
use App\Enums\ProductSubscriptionVisibility;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\ProductSubscription;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts subscription terms and exposes product and customer relationships', function (): void {
    $subscription = ProductSubscription::factory()->create([
        'discount_type' => ProductSubscriptionDiscountType::Fixed,
        'visibility' => ProductSubscriptionVisibility::Restricted,
        'discount_value' => 15,
        'is_active' => true,
    ]);
    $product = Product::factory()->create();
    $customerProfile = CustomerProfile::factory()->create();

    $subscription->products()->attach($product);
    $subscription->customerProfiles()->attach($customerProfile);

    expect($subscription->fresh()->discount_type)->toBe(ProductSubscriptionDiscountType::Fixed)
        ->and($subscription->fresh()->visibility)->toBe(ProductSubscriptionVisibility::Restricted)
        ->and($subscription->fresh()->discount_value)->toBe('15.00')
        ->and($subscription->fresh()->products)->toHaveCount(1)
        ->and($subscription->fresh()->customerProfiles)->toHaveCount(1)
        ->and($subscription->fresh()->status())->toBe('active');
});

it('keeps a restored subscription inactive', function (): void {
    $subscription = ProductSubscription::factory()->active()->create();

    $subscription->delete();
    $subscription->restore();

    expect($subscription->refresh()->is_active)->toBeFalse();
});

it('exposes current, scheduled, and expired validity scopes', function (): void {
    $currentSubscription = ProductSubscription::factory()->active()->create();
    $scheduledSubscription = ProductSubscription::factory()->scheduled()->create();
    $expiredSubscription = ProductSubscription::factory()->expired()->create();
    ProductSubscription::factory()->create();

    expect(ProductSubscription::current()->pluck('id')->all())->toContain($currentSubscription->id)
        ->not->toContain($scheduledSubscription->id, $expiredSubscription->id)
        ->and(ProductSubscription::scheduled()->pluck('id')->all())->toContain($scheduledSubscription->id)
        ->and(ProductSubscription::expired()->pluck('id')->all())->toContain($expiredSubscription->id);
});

it('enforces a globally unique subscription name after soft deletion', function (): void {
    ProductSubscription::factory()->create(['name' => 'Partner pricing'])->delete();

    expect(fn (): ProductSubscription => ProductSubscription::factory()->create(['name' => 'Partner pricing']))
        ->toThrow(UniqueConstraintViolationException::class);
});
