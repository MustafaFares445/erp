<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\ProductSubscription;
use App\Models\User;
use App\Services\Crm\ProductSubscriptionService;
use Database\Seeders\CrmPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('detaches only requested product and customer links and audits the changes', function (): void {
    (new CrmPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole('CRM Manager');
    $subscription = ProductSubscription::factory()->create(['created_by' => $actor, 'updated_by' => $actor]);
    $firstProduct = Product::factory()->create();
    $secondProduct = Product::factory()->create();
    $firstCustomer = CustomerProfile::factory()->create();
    $secondCustomer = CustomerProfile::factory()->create();
    $service = app(ProductSubscriptionService::class);

    $service->assignProducts($subscription, [$firstProduct->id, $secondProduct->id], $actor);
    $service->assignCustomers($subscription, [$firstCustomer->id, $secondCustomer->id], $actor);
    $service->unassignProducts($subscription, [$firstProduct->id], $actor);
    $service->unassignCustomers($subscription, [$firstCustomer->id], $actor);
    $freshSubscription = ProductSubscription::query()->findOrFail($subscription->id);

    expect($freshSubscription->products->modelKeys())->toEqual([$secondProduct->id])
        ->and($freshSubscription->customerProfiles->modelKeys())->toEqual([$secondCustomer->id])
        ->and(AuditLog::query()->pluck('action')->all())
        ->toContain('subscription.products.attached', 'subscription.products.detached', 'subscription.customers.assigned', 'subscription.customers.unassigned');
});

it('keeps customer assignment history when a customer becomes inactive', function (): void {
    (new CrmPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole('CRM Manager');
    $subscription = ProductSubscription::factory()->create(['created_by' => $actor, 'updated_by' => $actor]);
    $customer = CustomerProfile::factory()->create();

    app(ProductSubscriptionService::class)->assignCustomers($subscription, [$customer->id], $actor);
    $customer->update(['is_active' => false]);
    $freshSubscription = ProductSubscription::query()->findOrFail($subscription->id);

    expect($freshSubscription->customerProfiles->modelKeys())->toEqual([$customer->id]);
});
