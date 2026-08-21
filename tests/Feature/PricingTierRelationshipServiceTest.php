<?php

declare(strict_types=1);

use App\Enums\PricingTierType;
use App\Enums\ProductStatus;
use App\Models\AuditLog;
use App\Models\CustomerPricingTier;
use App\Models\CustomerProfile;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\PricingTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('synchronizes product and customer eligibility transactionally with audit history', function (): void {
    $actor = User::factory()->admin()->create();
    $tier = PricingTier::factory()->productScoped()->inactive()->create();
    $products = Product::factory()->count(2)->create(['status' => ProductStatus::Active]);
    $customers = CustomerProfile::factory()->count(2)->create();
    $service = app(PricingTierService::class);

    $service->syncProducts($tier, $products->modelKeys(), $actor);
    $service->syncCustomers($tier, $customers->pluck('user_id')->all(), $actor);
    $service->syncProducts($tier, [$products->last()->id], $actor);
    $service->syncCustomers($tier, [$customers->last()->user_id], $actor);

    expect($tier->products()->pluck('products.id')->all())->toBe([$products->last()->id])
        ->and($tier->assignments()->where('is_active', true)->pluck('customer_user_id')->all())->toBe([$customers->last()->user_id])
        ->and(AuditLog::query()->where('description', 'pricing.tier.products.synchronized')->count())->toBe(2)
        ->and(AuditLog::query()->where('description', 'pricing.tier.customers.synchronized')->count())->toBe(2);
});

it('rejects duplicate and inactive relationship targets without partial changes', function (): void {
    $actor = User::factory()->admin()->create();
    $tier = PricingTier::factory()->productScoped()->inactive()->create();
    $product = Product::factory()->create(['status' => ProductStatus::Active]);
    $inactiveProduct = Product::factory()->create(['status' => ProductStatus::Inactive, 'is_active' => false]);
    $inactiveCustomer = CustomerProfile::factory()->create(['is_active' => false]);
    $service = app(PricingTierService::class);
    $service->syncProducts($tier, [$product->id], $actor);

    expect(fn (): PricingTier => $service->syncProducts($tier, [$product->id, $product->id], $actor))
        ->toThrow(DomainException::class, 'Duplicate product links')
        ->and(fn (): PricingTier => $service->syncProducts($tier, [$inactiveProduct->id], $actor))
        ->toThrow(DomainException::class, 'active products')
        ->and(fn (): PricingTier => $service->syncCustomers($tier, [$inactiveCustomer->user_id], $actor))
        ->toThrow(DomainException::class, 'active customer profiles')
        ->and($tier->products()->pluck('products.id')->all())->toBe([$product->id]);
});

it('replaces only the active general assignment and keeps product-scoped assignments', function (): void {
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $firstGeneral = PricingTier::factory()->create();
    $secondGeneral = PricingTier::factory()->create();
    $productTier = PricingTier::factory()->productScoped()->create();
    CustomerPricingTier::factory()->create([
        'customer_user_id' => $customer->user_id,
        'pricing_tier_id' => $productTier->id,
    ]);
    $service = app(PricingTierService::class);

    $first = $service->assignGeneralTier($customer->user, $firstGeneral, $actor);
    $second = $service->assignGeneralTier($customer->user, $secondGeneral, $actor);

    expect($first->refresh()->is_active)->toBeFalse()
        ->and($second->is_active)->toBeTrue()
        ->and(CustomerPricingTier::query()
            ->where('customer_user_id', $customer->user_id)
            ->where('is_active', true)
            ->whereHas('pricingTier', fn ($query) => $query->where('tier_type', PricingTierType::ProductScoped))
            ->exists())->toBeTrue();
});
