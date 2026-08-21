<?php

declare(strict_types=1);

use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\PricingTierVisibility;
use App\Filament\Resources\PricingTiers\Pages\ManagePricingTiers;
use App\Filament\Resources\PricingTiers\PricingTierResource;
use App\Models\CustomerPricingTier;
use App\Models\CustomerProfile;
use App\Models\PricingTier;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates and lists a product-scoped tier on the unified pricing tiers page', function (): void {
    (new CrmPermissionSeeder)->run();
    $manager = User::factory()->admin()->create();
    $manager->assignRole('CRM Manager');

    Livewire::actingAs($manager)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('create'), [
            'name' => 'Product agreement',
            'tier_type' => PricingTierType::ProductScoped->value,
            'discount_type' => PricingTierDiscountType::Fixed->value,
            'discount_value' => 15,
            'visibility' => PricingTierVisibility::Public->value,
            'valid_from' => today()->toDateString(),
            'valid_until' => today()->addMonth()->toDateString(),
            'is_active' => false,
        ])
        ->assertHasNoActionErrors()
        ->assertTableColumnExists('tier_type')
        ->assertTableColumnExists('products_count')
        ->assertTableFilterExists('product')
        ->assertTableFilterExists('near_expiry');

    $tier = PricingTier::query()->sole();

    expect($tier->tier_type)->toBe(PricingTierType::ProductScoped)
        ->and($tier->discount_type)->toBe(PricingTierDiscountType::Fixed)
        ->and($tier->visibility)->toBe(PricingTierVisibility::Public)
        ->and($tier->is_active)->toBeFalse();

    $this->actingAs($manager)->get('/admin/product-subscriptions')->assertNotFound();
});

it('filters unified pricing tiers by validity dates and customer eligibility', function (): void {
    (new CrmPermissionSeeder)->run();
    $manager = User::factory()->admin()->create();
    $manager->assignRole('CRM Manager');

    $profile = CustomerProfile::factory()->create();
    $inside = PricingTier::factory()->productScoped()->create(['valid_until' => today()->addDays(5)]);
    $outside = PricingTier::factory()->productScoped()->create(['valid_until' => today()->addMonth()]);
    CustomerPricingTier::factory()->create(['pricing_tier_id' => $inside->id, 'customer_user_id' => $profile->user_id]);

    Livewire::actingAs($manager)
        ->test(ManagePricingTiers::class)
        ->filterTable('near_expiry', [
            'from' => today()->toDateString(),
            'until' => today()->addWeek()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$inside])
        ->assertCanNotSeeTableRecords([$outside])
        ->resetTableFilters()
        ->filterTable('customer', $profile->user_id)
        ->assertCanSeeTableRecords([$inside])
        ->assertCanNotSeeTableRecords([$outside]);
});

it('filters product-scoped tiers by lifecycle status', function (): void {
    (new CrmPermissionSeeder)->run();
    $manager = User::factory()->admin()->create();
    $manager->assignRole('CRM Manager');

    $current = PricingTier::factory()->productScoped()->create();
    $scheduled = PricingTier::factory()->productScoped()->create(['valid_from' => today()->addDay()]);
    $expired = PricingTier::factory()->productScoped()->create(['valid_until' => today()->subDay()]);

    Livewire::actingAs($manager)
        ->test(ManagePricingTiers::class)
        ->filterTable('status', ['value' => 'current'])
        ->assertCanSeeTableRecords([$current])
        ->assertCanNotSeeTableRecords([$scheduled, $expired])
        ->filterTable('status', ['value' => 'scheduled'])
        ->assertCanSeeTableRecords([$scheduled])
        ->assertCanNotSeeTableRecords([$current, $expired])
        ->filterTable('status', ['value' => 'expired'])
        ->assertCanSeeTableRecords([$expired])
        ->assertCanNotSeeTableRecords([$current, $scheduled])
        ->filterTable('status', ['value' => 'invalid'])
        ->assertCanSeeTableRecords([$current, $scheduled, $expired]);

    auth()->logout();

    expect(PricingTierResource::canManage())->toBeFalse();
});
