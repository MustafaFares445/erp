<?php

declare(strict_types=1);

use App\Enums\ProductSubscriptionDiscountType;
use App\Enums\ProductSubscriptionVisibility;
use App\Filament\Resources\ProductSubscriptions\Pages\CreateProductSubscription;
use App\Filament\Resources\ProductSubscriptions\Pages\EditProductSubscription;
use App\Filament\Resources\ProductSubscriptions\Pages\ListProductSubscriptions;
use App\Filament\Resources\ProductSubscriptions\Pages\ViewProductSubscription;
use App\Filament\Resources\ProductSubscriptions\ProductSubscriptionResource;
use App\Models\Product;
use App\Models\ProductSubscription;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates, searches, and edits subscription definitions in the CRM resource', function (): void {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(CreateProductSubscription::class)
        ->fillForm([
            'name' => 'Clinic partnership',
            'discount_type' => ProductSubscriptionDiscountType::Percentage->value,
            'discount_value' => 10,
            'visibility' => ProductSubscriptionVisibility::Public->value,
            'valid_from' => today()->toDateString(),
            'valid_until' => today()->addMonth()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $subscription = ProductSubscription::query()->sole();

    Livewire::actingAs($admin)
        ->test(ListProductSubscriptions::class)
        ->searchTable('Clinic partnership')
        ->assertCanSeeTableRecords([$subscription]);

    Livewire::actingAs($admin)
        ->test(EditProductSubscription::class, ['record' => $subscription->getKey()])
        ->fillForm([
            'name' => 'Clinic partnership updated',
            'discount_type' => ProductSubscriptionDiscountType::Fixed->value,
            'discount_value' => 15,
            'visibility' => ProductSubscriptionVisibility::Restricted->value,
            'valid_from' => today()->toDateString(),
            'valid_until' => today()->addMonths(2)->toDateString(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subscription->refresh()->name)->toBe('Clinic partnership updated')
        ->and($subscription->discount_type)->toBe(ProductSubscriptionDiscountType::Fixed)
        ->and($subscription->visibility)->toBe(ProductSubscriptionVisibility::Restricted)
        ->and($subscription->is_active)->toBeFalse();
});

it('soft deletes and restores subscriptions as inactive without a force-delete UI action', function (): void {
    $admin = User::factory()->admin()->create();
    $subscription = ProductSubscription::factory()->active()->create();
    $subscription->products()->attach(Product::factory()->create());

    Livewire::actingAs($admin)
        ->test(ViewProductSubscription::class, ['record' => $subscription->getKey()])
        ->callAction('deactivate');

    expect($subscription->refresh()->is_active)->toBeFalse();

    Livewire::actingAs($admin)
        ->test(ViewProductSubscription::class, ['record' => $subscription->getKey()])
        ->callAction('activate');

    expect($subscription->refresh()->is_active)->toBeTrue();

    Livewire::actingAs($admin)
        ->test(EditProductSubscription::class, ['record' => $subscription->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified();

    expect(ProductSubscription::query()->find($subscription->id))->toBeNull();

    Livewire::actingAs($admin)
        ->test(EditProductSubscription::class, ['record' => $subscription->getKey()])
        ->callAction(RestoreAction::class)
        ->assertNotified();

    expect($subscription->refresh()->is_active)->toBeFalse()
        ->and($admin->can('forceDelete', $subscription))->toBeFalse();

    Livewire::actingAs($admin)
        ->test(ListProductSubscriptions::class)
        ->filterTable('trashed', 'with')
        ->assertCanSeeTableRecords([$subscription]);
});

it('enforces subscription resource access through the CRM permission matrix', function (): void {
    (new CrmPermissionSeeder)->run();
    $systemAdmin = User::factory()->admin()->create();
    $systemAdmin->assignRole('System Admin');

    $crmManager = User::factory()->admin()->create();
    $crmManager->assignRole('CRM Manager');

    $pricingManager = User::factory()->admin()->create();
    $pricingManager->assignRole('Pricing Manager');

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $customer = User::factory()->customer()->create();
    $subscription = ProductSubscription::factory()->create();

    expect($systemAdmin->can('create', ProductSubscription::class))->toBeTrue()
        ->and($systemAdmin->can('deleteAny', ProductSubscription::class))->toBeTrue()
        ->and($systemAdmin->can('restoreAny', ProductSubscription::class))->toBeTrue()
        ->and($crmManager->can('delete', $subscription))->toBeTrue()
        ->and($crmManager->can('restore', $subscription))->toBeFalse()
        ->and($pricingManager->can('update', $subscription))->toBeTrue()
        ->and($pricingManager->can('delete', $subscription))->toBeFalse()
        ->and($reviewer->can('view', $subscription))->toBeTrue()
        ->and($reviewer->can('update', $subscription))->toBeFalse();

    $this->actingAs($reviewer)
        ->get(ProductSubscriptionResource::getUrl('index'))
        ->assertOk();

    $this->actingAs($customer)
        ->get(ProductSubscriptionResource::getUrl('index'))
        ->assertForbidden();
});
