<?php

declare(strict_types=1);

use App\Data\Inventory\PriceFloorOverrideData;
use App\Data\Inventory\VariantPricingData;
use App\Enums\InventoryPermission;
use App\Enums\PriceChangeRequestStatus;
use App\Enums\ProductStatus;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\CustomerPricingTier;
use App\Models\CustomerProfile;
use App\Models\InventorySetting;
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
        InventoryPermission::PricingReview->value,
        InventoryPermission::PriceFloorApprove->value,
    ]);

    return $manager;
}

it('derives base price and atomically records an effective manual change', function (): void {
    $manager = pricingManager();
    $variant = ProductVariant::factory()->create(['cost_price' => 50, 'markup_percent' => 10, 'base_price' => 55, 'min_price' => 45]);

    $updated = app(ProductPricingService::class)->updateVariantPricing($variant, new VariantPricingData(80, 25, 90), $manager);

    expect($updated->cost_price)->toBe('80.00')
        ->and($updated->base_price)->toBe('100.00')
        ->and($updated->min_price)->toBe('90.00')
        ->and(PriceHistory::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'catalog.variant.price_updated')->count())->toBe(1);
});

it('uses the configured default markup and does not write history for a no-op', function (): void {
    $manager = pricingManager();
    InventorySetting::query()->create(['default_markup_percent' => 20, 'expiry_alert_days' => 30]);
    $variant = ProductVariant::factory()->create(['cost_price' => 100, 'markup_percent' => null, 'base_price' => 120]);
    $service = app(ProductPricingService::class);

    $service->updateVariantPricing($variant, new VariantPricingData(100, null, null), $manager);
    $service->updateVariantPricing($variant, new VariantPricingData(100, 20, null), $manager);

    expect($variant->refresh()->markup_percent)->toBe('20.00')
        ->and(PriceHistory::query()->count())->toBe(1);
});

it('denies administrative variant pricing without its inventory permission', function (): void {
    $actor = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->create(['cost_price' => 10, 'markup_percent' => 10, 'base_price' => 11]);

    expect(fn (): ProductVariant => app(ProductPricingService::class)->updateVariantPricing($variant, new VariantPricingData(20, 10, null), $actor))
        ->toThrow(AuthorizationException::class);
});

function pricingRequester(): User
{
    $requester = User::factory()->admin()->create();
    $requester->givePermissionTo([InventoryPermission::PricingManage->value]);

    return $requester;
}

it('creates a pending price change request instead of applying it when the actor cannot review pricing', function (): void {
    $requester = pricingRequester();
    $variant = ProductVariant::factory()->create(['cost_price' => 50, 'markup_percent' => 10, 'base_price' => 55, 'min_price' => 45]);

    $result = app(ProductPricingService::class)->updateVariantPricing($variant, new VariantPricingData(80, 25, 90), $requester);
    $request = PriceHistory::query()->sole();

    expect($result->cost_price)->toBe('50.00')
        ->and($variant->refresh()->cost_price)->toBe('50.00')
        ->and($request->status)->toBe(PriceChangeRequestStatus::Pending)
        ->and($request->cost_price)->toBe('80.00')
        ->and($request->changed_by)->toBe($requester->id)
        ->and($request->reviewed_by)->toBeNull();
});

it('approves a pending price change request and applies it to the variant', function (): void {
    $requester = pricingRequester();
    $reviewer = pricingManager();
    $variant = ProductVariant::factory()->create(['cost_price' => 50, 'markup_percent' => 10, 'base_price' => 55, 'min_price' => 45]);

    app(ProductPricingService::class)->updateVariantPricing($variant, new VariantPricingData(80, 25, 90), $requester);
    $request = PriceHistory::query()->sole();

    $approved = app(ProductPricingService::class)->approvePriceChangeRequest($request, $reviewer);

    expect($approved->status)->toBe(PriceChangeRequestStatus::Approved)
        ->and($approved->reviewed_by)->toBe($reviewer->id)
        ->and($variant->refresh()->cost_price)->toBe('80.00')
        ->and($variant->base_price)->toBe('100.00')
        ->and(AuditLog::query()->where('action', 'catalog.variant.price_change_request_approved')->count())->toBe(1);
});

it('rejects a pending price change request without applying it', function (): void {
    $requester = pricingRequester();
    $reviewer = pricingManager();
    $variant = ProductVariant::factory()->create(['cost_price' => 50, 'markup_percent' => 10, 'base_price' => 55, 'min_price' => 45]);

    app(ProductPricingService::class)->updateVariantPricing($variant, new VariantPricingData(80, 25, 90), $requester);
    $request = PriceHistory::query()->sole();

    $rejected = app(ProductPricingService::class)->rejectPriceChangeRequest($request, $reviewer);

    expect($rejected->status)->toBe(PriceChangeRequestStatus::Rejected)
        ->and($rejected->reviewed_by)->toBe($reviewer->id)
        ->and($variant->refresh()->cost_price)->toBe('50.00');
});

it('updates a pending price change request and approves it automatically', function (): void {
    $requester = pricingRequester();
    $reviewer = pricingManager();
    $variant = ProductVariant::factory()->create(['cost_price' => 50, 'markup_percent' => 10, 'base_price' => 55, 'min_price' => 45]);

    app(ProductPricingService::class)->updateVariantPricing($variant, new VariantPricingData(80, 25, 90), $requester);
    $request = PriceHistory::query()->sole();

    $updated = app(ProductPricingService::class)->updatePriceChangeRequest($request, new VariantPricingData(70, 20, 60), $reviewer);

    expect($updated->status)->toBe(PriceChangeRequestStatus::Approved)
        ->and($updated->reviewed_by)->toBe($reviewer->id)
        ->and($updated->cost_price)->toBe('70.00')
        ->and($updated->base_price)->toBe('84.00')
        ->and($variant->refresh()->cost_price)->toBe('70.00')
        ->and($variant->base_price)->toBe('84.00')
        ->and($variant->min_price)->toBe('60.00');
});

it('denies reviewing price change requests without the review permission', function (): void {
    $requester = pricingRequester();
    $variant = ProductVariant::factory()->create(['cost_price' => 50, 'markup_percent' => 10, 'base_price' => 55, 'min_price' => 45]);

    app(ProductPricingService::class)->updateVariantPricing($variant, new VariantPricingData(80, 25, 90), $requester);
    $request = PriceHistory::query()->sole();
    $service = app(ProductPricingService::class);

    expect(fn () => $service->approvePriceChangeRequest($request, $requester))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->rejectPriceChangeRequest($request, $requester))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->updatePriceChangeRequest($request, new VariantPricingData(70, 20, 60), $requester))->toThrow(AuthorizationException::class);
});

it('rejects reviewing a price change request that is no longer pending', function (): void {
    $requester = pricingRequester();
    $reviewer = pricingManager();
    $variant = ProductVariant::factory()->create(['cost_price' => 50, 'markup_percent' => 10, 'base_price' => 55, 'min_price' => 45]);

    app(ProductPricingService::class)->updateVariantPricing($variant, new VariantPricingData(80, 25, 90), $requester);
    $service = app(ProductPricingService::class);
    $service->approvePriceChangeRequest(PriceHistory::query()->sole(), $reviewer);

    expect(fn () => $service->approvePriceChangeRequest(PriceHistory::query()->sole(), $reviewer))->toThrow(DomainException::class, 'pending')
        ->and(fn () => $service->rejectPriceChangeRequest(PriceHistory::query()->sole(), $reviewer))->toThrow(DomainException::class, 'pending');
});

it('keeps reviewed price change requests immutable', function (): void {
    $manager = pricingManager();
    $variant = ProductVariant::factory()->create();
    $history = PriceHistory::factory()->for($variant, 'productVariant')->create(['changed_by' => $manager->id]);

    expect(fn () => $history->update(['cost_price' => 999]))->toThrow(LogicException::class)
        ->and(fn () => $history->delete())->toThrow(LogicException::class);
});

it('approves and audits a documented manual below-floor price', function (): void {
    $manager = pricingManager();
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 90]);

    $override = app(ProductPricingService::class)->approveFloorOverride(
        new PriceFloorOverrideData($variant->id, $profile->user_id, 85, 'Approved strategic sale'),
        $manager,
    );

    expect($override->customer_user_id)->toBe($profile->user_id)
        ->and($override->pricing_tier_id)->toBeNull()
        ->and($override->attempted_price)->toBe('85.00')
        ->and(AuditLog::query()->where('action', 'catalog.variant.price_floor_overridden')->count())->toBe(1);
});

it('requires and records the actual winning pricing-tier provenance', function (): void {
    $manager = pricingManager();
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 90, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $tier = PricingTier::factory()->productScoped()->create(['discount_value' => 20]);
    $tier->products()->attach($variant->product_id);
    CustomerPricingTier::factory()->create(['customer_user_id' => $profile->user_id, 'pricing_tier_id' => $tier->id]);
    $service = app(ProductPricingService::class);

    expect(fn () => $service->approveFloorOverride(
        new PriceFloorOverrideData($variant->id, $profile->user_id, 80, 'Missing provenance'),
        $manager,
    ))->toThrow(DomainException::class, 'provenance is required');

    $override = $service->approveFloorOverride(
        new PriceFloorOverrideData($variant->id, $profile->user_id, 80, 'Approved tier price', $tier->id),
        $manager,
    );

    expect($override->pricing_tier_id)->toBe($tier->id)
        ->and(AuditLog::query()->where('action', 'catalog.variant.price_floor_overridden')->sole()->new_values)
        ->toMatchArray(['pricing_tier_id' => $tier->id]);
});

it('keeps approved floor records immutable', function (): void {
    $manager = pricingManager();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 90]);
    $override = app(ProductPricingService::class)->approveFloorOverride(
        new PriceFloorOverrideData($variant->id, null, 85, 'Immutable approval'),
        $manager,
    );

    expect(fn () => $override->update(['reason' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => $override->delete())->toThrow(LogicException::class);
});

it('rejects invalid floor approval requests before persistence', function (): void {
    $manager = pricingManager();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 90]);
    $employee = User::factory()->create(['user_type' => UserType::Employee]);
    $service = app(ProductPricingService::class);

    expect(fn () => $service->approveFloorOverride(new PriceFloorOverrideData($variant->id, null, 85, ' '), $manager))
        ->toThrow(DomainException::class, 'reason is required')
        ->and(fn () => $service->approveFloorOverride(new PriceFloorOverrideData($variant->id, $employee->id, 85, 'Invalid customer'), $manager))
        ->toThrow(DomainException::class, 'active customer profile')
        ->and(fn () => $service->approveFloorOverride(new PriceFloorOverrideData($variant->id, null, 95, 'No override needed'), $manager))
        ->toThrow(DomainException::class, 'override');
});

it('rejects pricing-tier provenance that is not the resolved winner', function (): void {
    $manager = pricingManager();
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 90, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $winner = PricingTier::factory()->productScoped()->create(['discount_value' => 20]);
    $wrongTier = PricingTier::factory()->productScoped()->create(['discount_value' => 10]);

    foreach ([$winner, $wrongTier] as $tier) {
        $tier->products()->attach($variant->product_id);
        CustomerPricingTier::factory()->create(['customer_user_id' => $profile->user_id, 'pricing_tier_id' => $tier->id]);
    }

    expect(fn () => app(ProductPricingService::class)->approveFloorOverride(
        new PriceFloorOverrideData($variant->id, $profile->user_id, 80, 'Wrong provenance', $wrongTier->id),
        $manager,
    ))->toThrow(DomainException::class, 'not the resolved winner');
});

it('rejects invalid variant pricing boundaries and unsaved variants', function (
    ProductVariant $variant,
    VariantPricingData $pricing,
    string $message,
): void {
    expect(fn (): ProductVariant => app(ProductPricingService::class)->updateFromInventoryImport($variant, $pricing, User::factory()->admin()->create()))
        ->toThrow(DomainException::class, $message);
})->with([
    'negative cost' => [fn (): ProductVariant => ProductVariant::factory()->make(), new VariantPricingData(-1, 10, null), 'cannot be negative'],
    'negative minimum' => [fn (): ProductVariant => ProductVariant::factory()->make(), new VariantPricingData(10, 10, -1), 'cannot be negative'],
    'invalid markup' => [fn (): ProductVariant => ProductVariant::factory()->make(), new VariantPricingData(10, 101, null), 'between 0 and 100'],
    'unsaved variant' => [fn (): ProductVariant => ProductVariant::factory()->make(), new VariantPricingData(10, 10, null), 'persisted product variant'],
]);
