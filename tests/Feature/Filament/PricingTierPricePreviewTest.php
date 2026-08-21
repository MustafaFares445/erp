<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Filament\Resources\PricingTiers\Pages\ManagePricingTiers;
use App\Models\AuditLog;
use App\Models\CustomerPricingTier;
use App\Models\CustomerProfile;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('previews the resolved tier price without writing pricing or audit history', function (): void {
    (new CrmPermissionSeeder)->run();
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 120, 'min_price' => 110, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $tier = PricingTier::factory()->productScoped()->create(['discount_value' => 10]);
    $tier->products()->attach($variant->product_id);
    CustomerPricingTier::factory()->create(['customer_user_id' => $profile->user_id, 'pricing_tier_id' => $tier->id]);
    $auditCount = AuditLog::query()->count();

    Livewire::actingAs($reviewer)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('previewPrice')->table($tier), [
            'customer_user_id' => $profile->user_id,
            'product_variant_id' => $variant->id,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Resolved price: 108.00');

    expect(PriceHistory::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

it('marks an allowed base-price preview as successful', function (): void {
    (new CrmPermissionSeeder)->run();
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 120, 'min_price' => 100, 'status' => ProductStatus::Active]);

    Livewire::actingAs($reviewer)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('previewPrice')->table(PricingTier::factory()->create()), [
            'customer_user_id' => $profile->user_id,
            'product_variant_id' => $variant->id,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Resolved price: 120.00');
});
