<?php

declare(strict_types=1);

use App\Data\Inventory\PriceFloorOverrideData;
use App\Data\Inventory\PricingTierData;
use App\Data\Inventory\VariantPricingData;
use App\Enums\InventoryPermission;
use App\Models\AuditLog;
use App\Models\CustomerPricingTier;
use App\Models\InventorySetting;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\ProductPricingService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function pricingManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo([
        InventoryPermission::PricingView->value,
        InventoryPermission::PricingManage->value,
    ]);

    return $manager;
}

it('derives base price and atomically records each effective manual change', function (): void {
    $manager = pricingManager();
    $variant = ProductVariant::factory()->create([
        'cost_price' => 50,
        'markup_percent' => 10,
        'base_price' => 55,
        'min_price' => 45,
    ]);

    $updated = app(ProductPricingService::class)->updateVariantPricing(
        variant: $variant,
        pricing: new VariantPricingData(80, 25, 90),
        actor: $manager,
    );

    expect($updated->cost_price)->toBe('80.00')
        ->and($updated->markup_percent)->toBe('25.00')
        ->and($updated->base_price)->toBe('100.00')
        ->and($updated->min_price)->toBe('90.00')
        ->and(PriceHistory::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'catalog.variant.price_updated')->count())->toBe(1);

    $history = PriceHistory::query()->sole();

    expect($history->product_variant_id)->toBe($variant->id)
        ->and($history->changed_by)->toBe($manager->id)
        ->and($history->base_price)->toBe('100.00');
});

it('uses the configured default markup and does not write history for no-op saves', function (): void {
    $manager = pricingManager();
    InventorySetting::query()->create(['default_markup_percent' => 20, 'expiry_alert_days' => 30]);
    $variant = ProductVariant::factory()->create([
        'cost_price' => 100,
        'markup_percent' => null,
        'base_price' => 120,
        'min_price' => null,
    ]);

    $service = app(ProductPricingService::class);
    $service->updateVariantPricing($variant, new VariantPricingData(100, null, null), $manager);

    expect($variant->refresh()->markup_percent)->toBe('20.00')
        ->and(PriceHistory::query()->count())->toBe(1);

    $service->updateVariantPricing($variant, new VariantPricingData(100, 20, null), $manager);

    expect(PriceHistory::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'catalog.variant.price_updated')->count())->toBe(1);
});

it('allows inventory workflows to update cost without pricing management permission', function (): void {
    $receiver = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->create([
        'cost_price' => 10,
        'markup_percent' => 50,
        'base_price' => 15,
        'min_price' => 12,
    ]);

    app(ProductPricingService::class)->updateCostFromInventory($variant, 20, $receiver);

    expect($variant->refresh()->cost_price)->toBe('20.00')
        ->and($variant->base_price)->toBe('30.00')
        ->and($variant->min_price)->toBe('12.00')
        ->and(PriceHistory::query()->count())->toBe(1);
});

it('denies administrative price mutations without pricing management permission', function (): void {
    $actor = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->create(['cost_price' => 10, 'markup_percent' => 10, 'base_price' => 11]);

    expect(fn () => app(ProductPricingService::class)->updateVariantPricing($variant, new VariantPricingData(20, 10, null), $actor))
        ->toThrow(AuthorizationException::class);

    expect($variant->refresh()->cost_price)->toBe('10.00')
        ->and(PriceHistory::query()->count())->toBe(0);
});

it('keeps exactly one active general pricing tier assignment per customer', function (): void {
    $manager = pricingManager();
    $customer = User::factory()->customer()->create();
    $firstTier = PricingTier::factory()->create();
    $secondTier = PricingTier::factory()->create();
    $service = app(ProductPricingService::class);

    $firstAssignment = $service->assignGeneralTier($customer, $firstTier, $manager);
    $secondAssignment = $service->assignGeneralTier($customer, $secondTier, $manager);

    expect($firstAssignment->refresh()->is_active)->toBeFalse()
        ->and($secondAssignment->refresh()->is_active)->toBeTrue()
        ->and(CustomerPricingTier::query()
            ->where('customer_user_id', $customer->id)
            ->where('is_active', true)
            ->count())->toBe(1);

    $reactivated = $service->assignGeneralTier($customer, $firstTier, $manager);

    expect($reactivated->is($firstAssignment))->toBeTrue()
        ->and($firstAssignment->refresh()->is_active)->toBeTrue()
        ->and($secondAssignment->refresh()->is_active)->toBeFalse();
});

it('rejects general tier assignments for non-customers or ineligible tiers', function (): void {
    $manager = pricingManager();
    $administrator = User::factory()->admin()->create();
    $customer = User::factory()->customer()->create();
    $inactiveTier = PricingTier::factory()->create(['is_active' => false]);
    $specificTier = PricingTier::factory()->for($customer, 'customer')->create();
    $service = app(ProductPricingService::class);

    expect(fn () => $service->assignGeneralTier($administrator, PricingTier::factory()->create(), $manager))
        ->toThrow(DomainException::class)
        ->and(fn () => $service->assignGeneralTier($customer, $inactiveTier, $manager))
        ->toThrow(DomainException::class)
        ->and(fn () => $service->assignGeneralTier($customer, $specificTier, $manager))
        ->toThrow(DomainException::class);
});

it('keeps at most one active customer-specific tier', function (): void {
    $manager = pricingManager();
    $customer = User::factory()->customer()->create();
    $service = app(ProductPricingService::class);

    $first = $service->saveTier(null, new PricingTierData('Specific A', 15, $customer->id, true), $manager);
    $second = $service->saveTier(null, new PricingTierData('Specific B', 20, $customer->id, true), $manager);

    expect($first->refresh()->is_active)->toBeFalse()
        ->and($second->refresh()->is_active)->toBeTrue()
        ->and(PricingTier::query()
            ->where('customer_user_id', $customer->id)
            ->where('is_active', true)
            ->count())->toBe(1);
});

it('rejects customer-specific tiers for non-customer accounts', function (): void {
    $manager = pricingManager();

    expect(fn () => app(ProductPricingService::class)->saveTier(
        tier: null,
        pricingTier: new PricingTierData(
            name: 'Invalid specific tier',
            discountPercent: 10,
            customerUserId: User::factory()->employee()->create()->id,
            isActive: true,
        ),
        actor: $manager,
    ))->toThrow(DomainException::class);
});

it('approves and audits a documented below-floor price', function (): void {
    $manager = pricingManager();
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()->create(['min_price' => 90]);

    $override = app(ProductPricingService::class)->approveFloorOverride(
        approval: new PriceFloorOverrideData($variant->id, $customer->id, 85, 'Approved strategic sale'),
        actor: $manager,
    );

    expect($override->product_variant_id)->toBe($variant->id)
        ->and($override->customer_user_id)->toBe($customer->id)
        ->and($override->attempted_price)->toBe('85.00')
        ->and($override->min_price)->toBe('90.00')
        ->and($override->approved_by)->toBe($manager->id)
        ->and($override->approved_at)->not->toBeNull()
        ->and($override->reason)->toBe('Approved strategic sale')
        ->and(AuditLog::query()->where('action', 'catalog.variant.price_floor_overridden')->count())->toBe(1);
});

it('rejects unnecessary, undocumented, or unauthorized floor approvals', function (): void {
    $manager = pricingManager();
    $unauthorized = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->create(['min_price' => 90]);
    $service = app(ProductPricingService::class);

    expect(fn () => $service->approveFloorOverride(new PriceFloorOverrideData($variant->id, null, 90, 'Not needed'), $manager))
        ->toThrow(DomainException::class)
        ->and(fn () => $service->approveFloorOverride(new PriceFloorOverrideData($variant->id, null, 85, ' '), $manager))
        ->toThrow(DomainException::class)
        ->and(fn () => $service->approveFloorOverride(new PriceFloorOverrideData($variant->id, null, 85, 'No permission'), $unauthorized))
        ->toThrow(AuthorizationException::class)
        ->and(PriceFloorOverride::query()->count())->toBe(0);
});

it('keeps persisted floor override records immutable', function (): void {
    $manager = pricingManager();
    $variant = ProductVariant::factory()->create(['min_price' => 90]);
    $override = app(ProductPricingService::class)->approveFloorOverride(
        new PriceFloorOverrideData($variant->id, null, 85, 'Immutable approval'),
        $manager,
    );

    expect(fn () => $override->update(['reason' => 'Changed']))
        ->toThrow(LogicException::class)
        ->and(fn () => $override->delete())
        ->toThrow(LogicException::class);

    expect($override->refresh()->reason)->toBe('Immutable approval');
});

it('updates imported pricing through the same derived-price history path', function (): void {
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->create([
        'cost_price' => 10,
        'markup_percent' => 20,
        'base_price' => 12,
    ]);

    $updated = app(ProductPricingService::class)->updateFromInventoryImport(
        $variant,
        new VariantPricingData(25, 40, 20),
        $actor,
    );

    expect($updated->cost_price)->toBe('25.00')
        ->and($updated->base_price)->toBe('35.00')
        ->and($updated->min_price)->toBe('20.00')
        ->and(PriceHistory::query()->where('product_variant_id', $variant->getKey())->count())->toBe(1);
});

it('deletes restores and ignores no-op pricing tier lifecycle requests', function (): void {
    $manager = pricingManager();
    $service = app(ProductPricingService::class);
    $tier = PricingTier::factory()->create([
        'name' => 'Lifecycle',
        'discount_percent' => 12,
        'is_active' => true,
    ]);

    $sameTier = $service->saveTier(
        $tier,
        new PricingTierData('Lifecycle', 12, null, true),
        $manager,
    );

    expect($sameTier->is($tier))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'pricing.tier.updated')->count())->toBe(0)
        ->and($service->restoreTier($tier, $manager))->toBeFalse()
        ->and($service->deleteTier($tier, $manager))->toBeTrue()
        ->and($service->deleteTier($tier, $manager))->toBeFalse()
        ->and($service->restoreTier($tier, $manager))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'pricing.tier.deleted')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'pricing.tier.restored')->count())->toBe(1);
});

it('deactivates another customer-specific tier when restoring an active one', function (): void {
    $manager = pricingManager();
    $customer = User::factory()->customer()->create();
    $service = app(ProductPricingService::class);
    $restoredTier = PricingTier::factory()->for($customer, 'customer')->create([
        'name' => 'Restored specific',
        'is_active' => true,
    ]);
    $service->deleteTier($restoredTier, $manager);
    $currentTier = PricingTier::factory()->for($customer, 'customer')->create([
        'name' => 'Current specific',
        'is_active' => true,
    ]);

    expect($service->restoreTier($restoredTier, $manager))->toBeTrue()
        ->and($restoredTier->refresh()->is_active)->toBeTrue()
        ->and($currentTier->refresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'pricing.tier.deactivated')->count())->toBe(1);
});

it('skips inactive historical assignments when assigning a general tier', function (): void {
    $manager = pricingManager();
    $customer = User::factory()->customer()->create();
    $historicalTier = PricingTier::factory()->create();
    $targetTier = PricingTier::factory()->create();
    $historical = CustomerPricingTier::factory()->create([
        'customer_user_id' => $customer->getKey(),
        'pricing_tier_id' => $historicalTier->getKey(),
        'is_active' => false,
    ]);

    $assigned = app(ProductPricingService::class)->assignGeneralTier($customer, $targetTier, $manager);

    expect($assigned->is_active)->toBeTrue()
        ->and($historical->fresh()->is_active)->toBeFalse();
});

it('validates every pricing boundary before making changes', function (): void {
    $manager = pricingManager();
    $actor = User::factory()->create();
    $service = app(ProductPricingService::class);
    $variant = ProductVariant::factory()->create();

    foreach ([
        new VariantPricingData(-1, 10, null),
        new VariantPricingData(1, -1, null),
        new VariantPricingData(1, 101, null),
        new VariantPricingData(1, 10, -1),
    ] as $invalidPricing) {
        expect(fn () => $service->updateFromInventoryImport($variant, $invalidPricing, $actor))
            ->toThrow(DomainException::class);
    }

    expect(fn () => $service->updateCostFromInventory($variant, -1, $actor))
        ->toThrow(DomainException::class, 'Cost price cannot be negative.')
        ->and(fn () => $service->saveTier(null, new PricingTierData(' ', 10, null, true), $manager))
        ->toThrow(DomainException::class, 'A pricing tier name is required.')
        ->and(fn () => $service->saveTier(null, new PricingTierData('Invalid discount', 101, null, true), $manager))
        ->toThrow(DomainException::class, 'Discount percentage must be between 0 and 100.')
        ->and(fn () => $service->assignGeneralTier(
            User::factory()->customer()->create(),
            new PricingTier,
            $manager,
        ))->toThrow(DomainException::class, 'A persisted pricing tier is required.');
});

it('rejects floor overrides without a configured floor or for non-customers', function (): void {
    $manager = pricingManager();
    $service = app(ProductPricingService::class);
    $withoutFloor = ProductVariant::factory()->create(['min_price' => null]);
    $withFloor = ProductVariant::factory()->create(['min_price' => 50]);
    $employee = User::factory()->employee()->create();

    expect(fn () => $service->approveFloorOverride(
        new PriceFloorOverrideData($withoutFloor->getKey(), null, 1, 'No configured floor'),
        $manager,
    ))->toThrow(DomainException::class, __('admin.inventory.pricing.errors.override_not_required'))
        ->and(fn () => $service->approveFloorOverride(
            new PriceFloorOverrideData($withFloor->getKey(), $employee->getKey(), 40, 'Wrong account type'),
            $manager,
        ))->toThrow(DomainException::class, 'Pricing tiers can only be assigned to customer accounts.');
});
