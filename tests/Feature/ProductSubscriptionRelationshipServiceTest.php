<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\ProductSubscription;
use App\Models\User;
use App\Services\Crm\ProductSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('assigns active products and customers transactionally and records relationship audit events', function (): void {
    $actor = User::factory()->admin()->create();
    $subscription = ProductSubscription::factory()->create();
    $product = Product::factory()->create(['status' => ProductStatus::Active]);
    $customer = CustomerProfile::factory()->create();
    $service = app(ProductSubscriptionService::class);

    $service->assignProducts($subscription, [$product->id], $actor);
    $service->assignCustomers($subscription, [$customer->id], $actor);

    expect($subscription->products()->pluck('products.id')->all())->toBe([$product->id])
        ->and($subscription->customerProfiles()->pluck('customer_profiles.id')->all())->toBe([$customer->id])
        ->and(AuditLog::query()->where('action', 'subscription.products.attached')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'subscription.customers.assigned')->exists())->toBeTrue();
});

it('rejects duplicate, inactive, and deleted relationship targets without changing existing links', function (): void {
    $actor = User::factory()->admin()->create();
    $subscription = ProductSubscription::factory()->create();
    $product = Product::factory()->create(['status' => ProductStatus::Active]);
    $inactiveProduct = Product::factory()->create(['is_active' => false, 'status' => ProductStatus::Inactive]);
    $customer = CustomerProfile::factory()->create();
    $inactiveCustomer = CustomerProfile::factory()->create(['is_active' => false]);
    $deletedCustomer = CustomerProfile::factory()->create();
    $deletedCustomer->delete();

    $service = app(ProductSubscriptionService::class);

    $service->assignProducts($subscription, [$product->id], $actor);
    $service->assignCustomers($subscription, [$customer->id], $actor);

    expect(fn (): ProductSubscription => $service->assignProducts($subscription, [$product->id], $actor))
        ->toThrow(DomainException::class, 'only be linked')
        ->and(fn (): ProductSubscription => $service->assignProducts($subscription, [$inactiveProduct->id], $actor))
        ->toThrow(DomainException::class, 'active products')
        ->and(fn (): ProductSubscription => $service->assignCustomers($subscription, [$inactiveCustomer->id], $actor))
        ->toThrow(DomainException::class, 'active customer profiles')
        ->and(fn (): ProductSubscription => $service->assignCustomers($subscription, [$deletedCustomer->id], $actor))
        ->toThrow(DomainException::class, 'active customer profiles')
        ->and($subscription->products()->pluck('products.id')->all())->toBe([$product->id])
        ->and($subscription->customerProfiles()->pluck('customer_profiles.id')->all())->toBe([$customer->id]);
});

it('detaches only the selected product and customer links', function (): void {
    $actor = User::factory()->admin()->create();
    $subscription = ProductSubscription::factory()->create();
    $firstProduct = Product::factory()->create(['status' => ProductStatus::Active]);
    $secondProduct = Product::factory()->create(['status' => ProductStatus::Active]);
    $firstCustomer = CustomerProfile::factory()->create();
    $secondCustomer = CustomerProfile::factory()->create();
    $service = app(ProductSubscriptionService::class);

    $service->assignProducts($subscription, [$firstProduct->id, $secondProduct->id], $actor);
    $service->assignCustomers($subscription, [$firstCustomer->id, $secondCustomer->id], $actor);
    $service->unassignProducts($subscription, [$firstProduct->id], $actor);
    $service->unassignCustomers($subscription, [$firstCustomer->id], $actor);

    expect($subscription->products()->pluck('products.id')->all())->toBe([$secondProduct->id])
        ->and($subscription->customerProfiles()->pluck('customer_profiles.id')->all())->toBe([$secondCustomer->id])
        ->and(AuditLog::query()->where('action', 'subscription.products.detached')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'subscription.customers.unassigned')->exists())->toBeTrue();
});

it('uses a bounded number of queries when adding a relationship to a large subscription', function (): void {
    $actor = User::factory()->admin()->create();
    $subscription = ProductSubscription::factory()->create();
    $existingProducts = Product::factory()->count(25)->create(['status' => ProductStatus::Active]);
    $newProduct = Product::factory()->create(['status' => ProductStatus::Active]);
    $subscription->products()->attach($existingProducts->modelKeys());
    $service = app(ProductSubscriptionService::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $service->assignProducts($subscription, [$newProduct->id], $actor);

    $queryCount = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($queryCount)->toBeLessThanOrEqual(8)
        ->and($subscription->products()->whereKey($newProduct)->exists())->toBeTrue();
});
