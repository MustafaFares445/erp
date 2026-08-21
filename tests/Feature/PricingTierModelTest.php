<?php

declare(strict_types=1);

use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\PricingTierVisibility;
use App\Models\CustomerProfile;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts pricing tier terms and exposes product and customer relationships', function (): void {
    $tier = PricingTier::factory()->fixed()->restricted()->create(['discount_value' => 15]);
    $product = Product::factory()->create();
    $customer = CustomerProfile::factory()->create();

    $tier->products()->attach($product);
    $tier->assignments()->create(['customer_user_id' => $customer->user_id, 'is_active' => true]);

    expect($tier->fresh()->tier_type)->toBe(PricingTierType::ProductScoped)
        ->and($tier->fresh()->discount_type)->toBe(PricingTierDiscountType::Fixed)
        ->and($tier->fresh()->visibility)->toBe(PricingTierVisibility::Restricted)
        ->and($tier->fresh()->discount_value)->toBe('15.00')
        ->and($tier->fresh()->products)->toHaveCount(1)
        ->and($tier->fresh()->assignments)->toHaveCount(1)
        ->and($tier->fresh()->status())->toBe('active');
});

it('exposes current scheduled and expired validity scopes', function (): void {
    $current = PricingTier::factory()->productScoped()->create();
    $scheduled = PricingTier::factory()->productScoped()->create(['valid_from' => today()->addDay()]);
    $expired = PricingTier::factory()->productScoped()->create(['valid_until' => today()->subDay()]);
    PricingTier::factory()->productScoped()->inactive()->create();

    expect(PricingTier::current()->pluck('id')->all())->toContain($current->id)
        ->not->toContain($scheduled->id, $expired->id)
        ->and(PricingTier::scheduled()->pluck('id')->all())->toContain($scheduled->id)
        ->and(PricingTier::expired()->pluck('id')->all())->toContain($expired->id);
});

it('enforces a globally unique tier name after soft deletion', function (): void {
    PricingTier::factory()->create(['name' => 'Partner pricing'])->delete();

    expect(fn (): PricingTier => PricingTier::factory()->create(['name' => 'Partner pricing']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('exposes blame relationships and every lifecycle status', function (): void {
    $creator = User::factory()->create();
    $updater = User::factory()->create();
    $scheduled = PricingTier::factory()->productScoped()->create([
        'created_by' => $creator->id,
        'updated_by' => $updater->id,
        'valid_from' => today()->addDay(),
    ]);
    $expired = PricingTier::factory()->productScoped()->create(['valid_until' => today()->subDay()]);

    expect($scheduled->creator->is($creator))->toBeTrue()
        ->and($scheduled->updater->is($updater))->toBeTrue()
        ->and($scheduled->status())->toBe('scheduled')
        ->and($scheduled->status)->toBe('scheduled')
        ->and($expired->status())->toBe('expired');
});
