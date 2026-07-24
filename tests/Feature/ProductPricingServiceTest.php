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
