<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Filament\Resources\PricingTiers\Pages\ManagePricingTiers;
use App\Models\CustomerProfile;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('manages product and customer eligibility and activation from pricing tiers', function (): void {
    (new CrmPermissionSeeder)->run();
    $manager = User::factory()->admin()->create();
    $manager->assignRole('CRM Manager');

    $tier = PricingTier::factory()->productScoped()->inactive()->create();
    $product = Product::factory()->create(['status' => ProductStatus::Active]);
    $profile = CustomerProfile::factory()->create();

    Livewire::actingAs($manager)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('manageProducts')->table($tier), ['product_ids' => [$product->id]])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('manageCustomers')->table($tier), ['customer_user_ids' => [$profile->user_id]])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('activate')->table($tier))
        ->assertHasNoActionErrors();

    expect($tier->products()->pluck('products.id')->all())->toBe([$product->id])
        ->and($tier->assignments()->where('is_active', true)->pluck('customer_user_id')->all())->toBe([$profile->user_id])
        ->and($tier->refresh()->is_active)->toBeTrue();
});

it('hides relationship mutation actions from pricing managers', function (): void {
    (new CrmPermissionSeeder)->run();
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Pricing Manager');

    $tier = PricingTier::factory()->productScoped()->inactive()->create();

    Livewire::actingAs($manager)
        ->test(ManagePricingTiers::class)
        ->assertActionHidden(TestAction::make('manageProducts')->table($tier))
        ->assertActionHidden(TestAction::make('manageCustomers')->table($tier))
        ->assertActionHidden(TestAction::make('activate')->table($tier));
});
