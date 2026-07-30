<?php

declare(strict_types=1);

use App\Enums\ProductSubscriptionDiscountType;
use App\Enums\ProductSubscriptionVisibility;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\ProductSubscription;
use App\Models\User;
use App\Services\Crm\ProductSubscriptionService;
use Database\Seeders\CrmPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates subscription terms and linked products transactionally', function (): void {
    (new CrmPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole('CRM Manager');
    $product = Product::factory()->create();

    $subscription = app(ProductSubscriptionService::class)->create([
        'name' => 'Clinic partnership',
        'discount_type' => ProductSubscriptionDiscountType::Percentage,
        'discount_value' => 10,
        'visibility' => ProductSubscriptionVisibility::Public,
    ], [$product->id], [], $actor);
    $activatedSubscription = app(ProductSubscriptionService::class)->activate($subscription, $actor);

    expect($subscription->products)->toHaveCount(1)
        ->and($subscription->is_active)->toBeFalse()
        ->and($activatedSubscription->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'subscription.created')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'subscription.activated')->exists())->toBeTrue();
});

it('rejects activating a restricted subscription without an active assignment', function (): void {
    (new CrmPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole('CRM Manager');
    $subscription = ProductSubscription::factory()->restricted()->create(['created_by' => $actor, 'updated_by' => $actor]);
    $subscription->products()->attach(Product::factory()->create());

    expect(fn (): ProductSubscription => app(ProductSubscriptionService::class)->activate($subscription, $actor))
        ->toThrow(DomainException::class, 'active customer assignment');
});

it('rejects inactive customers from new assignments', function (): void {
    (new CrmPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole('CRM Manager');
    $customerProfile = CustomerProfile::factory()->create(['is_active' => false]);
    $subscription = ProductSubscription::factory()->create(['created_by' => $actor, 'updated_by' => $actor]);

    expect(fn (): ProductSubscription => app(ProductSubscriptionService::class)->assignCustomers($subscription, [$customerProfile->id], $actor))
        ->toThrow(DomainException::class, 'active customer profiles');
});

it('updates terms, deactivates, deletes, and restores through lifecycle operations', function (): void {
    (new CrmPermissionSeeder)->run();
    $systemAdmin = User::factory()->admin()->create();
    $systemAdmin->assignRole('System Admin');
    $subscription = ProductSubscription::factory()->active()->create([
        'created_by' => $systemAdmin,
        'updated_by' => $systemAdmin,
    ]);
    $service = app(ProductSubscriptionService::class);

    $updatedSubscription = $service->update($subscription, [
        'name' => 'Updated partnership',
        'discount_type' => ProductSubscriptionDiscountType::Fixed,
        'discount_value' => 15,
        'visibility' => ProductSubscriptionVisibility::Restricted,
        'valid_from' => today()->toDateString(),
        'valid_until' => today()->addMonth()->toDateString(),
    ], $systemAdmin);
    $deactivatedSubscription = $service->deactivate($updatedSubscription, $systemAdmin);
    $service->delete($deactivatedSubscription, $systemAdmin);
    $restoredSubscription = $service->restore($deactivatedSubscription, $systemAdmin);

    expect($updatedSubscription->discount_type)->toBe(ProductSubscriptionDiscountType::Fixed)
        ->and($deactivatedSubscription->is_active)->toBeFalse()
        ->and($restoredSubscription->is_active)->toBeFalse()
        ->and(AuditLog::query()->pluck('action')->all())
        ->toContain('subscription.updated', 'subscription.deactivated', 'subscription.deleted', 'subscription.restored');
});

it('rejects invalid terms, duplicate links, and duplicate names without partial records', function (): void {
    (new CrmPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole('CRM Manager');
    $product = Product::factory()->create();
    $service = app(ProductSubscriptionService::class);

    expect(fn (): ProductSubscription => $service->create([
        'name' => 'Invalid date range',
        'discount_type' => ProductSubscriptionDiscountType::Percentage,
        'discount_value' => 10,
        'visibility' => ProductSubscriptionVisibility::Public,
        'valid_from' => today()->addDay(),
        'valid_until' => today(),
    ], [], [], $actor))->toThrow(DomainException::class, 'cannot precede')
        ->and(fn (): ProductSubscription => $service->create([
            'name' => 'Duplicate link',
            'discount_type' => ProductSubscriptionDiscountType::Percentage,
            'discount_value' => 10,
            'visibility' => ProductSubscriptionVisibility::Public,
        ], [$product->id, $product->id], [], $actor))->toThrow(DomainException::class, 'Duplicate product links');

    expect(ProductSubscription::query()->count())->toBe(0);

    $service->create([
        'name' => 'Unique partner',
        'discount_type' => ProductSubscriptionDiscountType::Percentage,
        'discount_value' => 10,
        'visibility' => ProductSubscriptionVisibility::Public,
    ], [], [], $actor);

    expect(fn (): ProductSubscription => $service->create([
        'name' => 'Unique partner',
        'discount_type' => ProductSubscriptionDiscountType::Fixed,
        'discount_value' => 10,
        'visibility' => ProductSubscriptionVisibility::Public,
    ], [], [], $actor))->toThrow(DomainException::class, 'already exists')
        ->and(ProductSubscription::query()->count())->toBe(1);
});

it('allows pricing managers to edit only discount terms', function (): void {
    (new CrmPermissionSeeder)->run();
    $pricingManager = User::factory()->admin()->create();
    $pricingManager->assignRole('Pricing Manager');
    $subscription = ProductSubscription::factory()->create();
    $service = app(ProductSubscriptionService::class);

    $updatedSubscription = $service->update($subscription, [
        'name' => $subscription->name,
        'discount_type' => ProductSubscriptionDiscountType::Fixed,
        'discount_value' => 12,
        'visibility' => $subscription->visibility,
        'valid_from' => $subscription->valid_from,
        'valid_until' => $subscription->valid_until,
    ], $pricingManager);

    expect($updatedSubscription->discount_value)->toBe('12.00')
        ->and(fn (): ProductSubscription => $service->update($updatedSubscription, [
            'name' => 'Not allowed',
            'discount_type' => $updatedSubscription->discount_type,
            'discount_value' => 12,
            'visibility' => $updatedSubscription->visibility,
            'valid_from' => $updatedSubscription->valid_from,
            'valid_until' => $updatedSubscription->valid_until,
        ], $pricingManager))->toThrow(DomainException::class, 'not authorized');
});
