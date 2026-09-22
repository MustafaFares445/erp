<?php

declare(strict_types=1);

use App\Data\Inventory\PricingTierData;
use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\PricingTierVisibility;
use App\Enums\ProductStatus;
use App\Models\BusinessConstraint;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\PriceResolver;
use App\Services\Inventory\PricingTierService;
use App\Services\Settings\BusinessConstraintService;
use App\Services\Settings\Exceptions\ConstraintBreached;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actor = User::factory()->admin()->create();
    $this->tiers = app(PricingTierService::class);
});

function generalTierData(float $discountValue, string $name = 'Volume'): PricingTierData
{
    return new PricingTierData(
        name: $name,
        tierType: PricingTierType::General,
        discountType: PricingTierDiscountType::Percentage,
        discountValue: $discountValue,
        isActive: true,
    );
}

it('accepts a percentage discount at or under the ceiling', function (float $discount): void {
    $tier = $this->tiers->save(null, generalTierData($discount), $this->actor);

    expect((float) $tier->discount_value)->toBe($discount);
})->with([5.0, 24.99, 25.0]);

it('refuses a percentage discount past the default ceiling', function (): void {
    // The headline rule: before this, a 40% tier saved without comment.
    $this->tiers->save(null, generalTierData(40.0), $this->actor);
})->throws(ConstraintBreached::class);

it('explains the refusal in terms the author can act on', function (): void {
    try {
        $this->tiers->save(null, generalTierData(40.0), $this->actor);
    } catch (ConstraintBreached $constraintBreached) {
        expect($constraintBreached->getMessage())->toContain('Maximum discount')
            ->and($constraintBreached->getMessage())->toContain('25%')
            ->and($constraintBreached->approvable)->toBeTrue();

        return;
    }

    $this->fail('A 40% tier saved with no ceiling applied.');
});

it('saves a discount past the ceiling when a matching approval is supplied', function (): void {
    $approval = app(BusinessConstraintService::class)->approveOverride(
        BusinessConstraintKey::MaxDiscountPercent,
        40.0,
        'Agreed for the annual renewal.',
        $this->actor,
    );

    $tier = $this->tiers->save(null, generalTierData(40.0), $this->actor, $approval);

    expect((float) $tier->discount_value)->toBe(40.0);
});

it('refuses an approval granted for a different discount', function (): void {
    $approval = app(BusinessConstraintService::class)->approveOverride(
        BusinessConstraintKey::MaxDiscountPercent,
        30.0,
        'Agreed for the annual renewal.',
        $this->actor,
    );

    $this->tiers->save(null, generalTierData(45.0), $this->actor, $approval);
})->throws(ConstraintBreached::class, 'does not authorise this value');

it('refuses outright, with no approval possible, when the ceiling blocks', function (): void {
    BusinessConstraint::factory()
        ->limit(BusinessConstraintKey::MaxDiscountPercent, 25.0, BusinessConstraintEnforcement::Block)
        ->create();

    try {
        $this->tiers->save(null, generalTierData(40.0), $this->actor);
    } catch (ConstraintBreached $constraintBreached) {
        expect($constraintBreached->approvable)->toBeFalse();

        return;
    }

    $this->fail('A blocking ceiling let a 40% tier through.');
});

it('lets a discount past a ceiling that only warns', function (): void {
    BusinessConstraint::factory()
        ->limit(BusinessConstraintKey::MaxDiscountPercent, 25.0, BusinessConstraintEnforcement::Warn)
        ->create();

    $tier = $this->tiers->save(null, generalTierData(40.0), $this->actor);

    expect((float) $tier->discount_value)->toBe(40.0);
});

it('honours a ceiling the owner has moved', function (): void {
    BusinessConstraint::factory()
        ->limit(BusinessConstraintKey::MaxDiscountPercent, 50.0, BusinessConstraintEnforcement::Block)
        ->create();

    expect((float) $this->tiers->save(null, generalTierData(40.0), $this->actor)->discount_value)->toBe(40.0);
});

it('never re-checks the ceiling when resolving a price, so an existing tier keeps working', function (): void {
    // Enforcement belongs to authoring. If it ran on resolution too, every
    // price preview, report and quotation line for an approved or
    // grandfathered tier would throw.
    $approval = app(BusinessConstraintService::class)->approveOverride(
        BusinessConstraintKey::MaxDiscountPercent,
        60.0,
        'Clearance.',
        $this->actor,
    );
    $this->tiers->save(null, generalTierData(60.0), $this->actor, $approval);

    $customer = User::factory()->customer()->create();
    $customer->customerProfile()->create(['customer_code' => 'C-CEIL', 'is_active' => true]);
    $tier = PricingTier::query()->firstOrFail();
    $this->tiers->assignGeneralTier($customer, $tier, $this->actor);

    $product = Product::factory()->create(['is_active' => true, 'status' => ProductStatus::Active]);
    $variant = ProductVariant::factory()->for($product)->create([
        'is_active' => true,
        'status' => ProductStatus::Active,
        'base_price' => 100,
        'min_price' => null,
    ]);

    expect(app(PriceResolver::class)->resolve($variant, $customer)->amount)->toBe(40.0);
});

describe('fixed discounts', function (): void {
    it('holds a fixed discount to the same ceiling as a percentage one', function (): void {
        // A flat 90 off a 100 variant is a 90% discount however it is spelled.
        // Without this, switching the discount type would sidestep the rule.
        $product = Product::factory()->create(['is_active' => true, 'status' => ProductStatus::Active]);
        ProductVariant::factory()->for($product)->create([
            'is_active' => true,
            'status' => ProductStatus::Active,
            'base_price' => 100,
        ]);

        $tier = $this->tiers->save(null, new PricingTierData(
            name: 'Clearance',
            tierType: PricingTierType::ProductScoped,
            discountType: PricingTierDiscountType::Fixed,
            discountValue: 90.0,
            visibility: PricingTierVisibility::Public,
            isActive: false,
        ), $this->actor);

        $this->tiers->syncProducts($tier, [$product->id], $this->actor);

        expect(fn (): PricingTier => $this->tiers->activate($tier->refresh(), $this->actor))
            ->toThrow(ConstraintBreached::class);
    });

    it('measures a fixed discount against the cheapest linked variant', function (): void {
        // 20 off is 10% of the dear variant but 40% of the cheap one, and the
        // cheap one is where the rule has to bite.
        $product = Product::factory()->create(['is_active' => true, 'status' => ProductStatus::Active]);
        ProductVariant::factory()->for($product)->create([
            'is_active' => true,
            'status' => ProductStatus::Active,
            'base_price' => 200,
        ]);
        ProductVariant::factory()->for($product)->create([
            'is_active' => true,
            'status' => ProductStatus::Active,
            'base_price' => 50,
        ]);

        $tier = $this->tiers->save(null, new PricingTierData(
            name: 'Flat twenty',
            tierType: PricingTierType::ProductScoped,
            discountType: PricingTierDiscountType::Fixed,
            discountValue: 20.0,
            visibility: PricingTierVisibility::Public,
            isActive: false,
        ), $this->actor);

        $this->tiers->syncProducts($tier, [$product->id], $this->actor);

        expect(fn (): PricingTier => $this->tiers->activate($tier->refresh(), $this->actor))
            ->toThrow(ConstraintBreached::class);
    });

    it('activates a fixed discount that stays under the ceiling everywhere', function (): void {
        $product = Product::factory()->create(['is_active' => true, 'status' => ProductStatus::Active]);
        ProductVariant::factory()->for($product)->create([
            'is_active' => true,
            'status' => ProductStatus::Active,
            'base_price' => 200,
        ]);

        $tier = $this->tiers->save(null, new PricingTierData(
            name: 'Flat twenty',
            tierType: PricingTierType::ProductScoped,
            discountType: PricingTierDiscountType::Fixed,
            discountValue: 20.0,
            visibility: PricingTierVisibility::Public,
            isActive: false,
        ), $this->actor);

        $this->tiers->syncProducts($tier, [$product->id], $this->actor);

        expect($this->tiers->activate($tier->refresh(), $this->actor)->is_active)->toBeTrue();
    });
});
