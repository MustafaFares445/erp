<?php

declare(strict_types=1);

use App\Enums\InventoryReportType;
use App\Enums\PricingTierType;
use App\Models\CustomerPricingTier;
use App\Models\CustomerProfile;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryReportFormatter;
use App\Services\Inventory\InventoryReportService;
use Database\Seeders\CrmPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('filters pricing tier and assignment reports by type product and customer', function (): void {
    $profile = CustomerProfile::factory()->create();
    $product = Product::factory()->create();
    $tier = PricingTier::factory()->productScoped()->create();
    $tier->products()->attach($product);
    $assignment = CustomerPricingTier::factory()->create(['customer_user_id' => $profile->user_id, 'pricing_tier_id' => $tier->id]);
    PricingTier::factory()->create();
    $service = app(InventoryReportService::class);

    $tierRecords = $service->query(InventoryReportType::PricingTiers, [
        'tier_type' => PricingTierType::ProductScoped->value,
        'product_id' => $product->id,
        'customer_user_id' => $profile->user_id,
    ])->get();
    $assignmentRecords = $service->query(InventoryReportType::CustomerAssignments, [
        'product_id' => $product->id,
        'customer_user_id' => $profile->user_id,
    ])->get();

    expect($tierRecords->modelKeys())->toBe([$tier->id])
        ->and($assignmentRecords->modelKeys())->toBe([$assignment->id])
        ->and(app(InventoryReportFormatter::class)->headings(InventoryReportType::PricingTiers, true))
        ->toContain('Type', 'Discount type', 'Products', 'Active customers');
});

it('allows fixed CRM reporting roles to view pricing reports', function (): void {
    (new CrmPermissionSeeder)->run();
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    expect(app(InventoryReportService::class)->canView($reviewer, InventoryReportType::PricingTiers))->toBeTrue()
        ->and(app(InventoryReportService::class)->canView($reviewer, InventoryReportType::CustomerAssignments))->toBeTrue();
});

it('filters pricing reports by lifecycle and assignment tier type', function (): void {
    $service = app(InventoryReportService::class);
    $current = PricingTier::factory()->productScoped()->create();
    $scheduled = PricingTier::factory()->productScoped()->create(['valid_from' => today()->addDay()]);
    $expired = PricingTier::factory()->productScoped()->create(['valid_until' => today()->subDay()]);
    $assignment = CustomerPricingTier::factory()->create(['pricing_tier_id' => $current->id]);

    expect($service->query(InventoryReportType::PricingTiers, ['eligibility_state' => 'current'])->pluck('id')->all())->toContain($current->id)
        ->not->toContain($scheduled->id, $expired->id)
        ->and($service->query(InventoryReportType::PricingTiers, ['eligibility_state' => 'scheduled'])->pluck('id')->all())->toBe([$scheduled->id])
        ->and($service->query(InventoryReportType::PricingTiers, ['eligibility_state' => 'expired'])->pluck('id')->all())->toBe([$expired->id])
        ->and($service->query(InventoryReportType::CustomerAssignments, ['tier_type' => PricingTierType::ProductScoped->value])->pluck('id')->all())->toBe([$assignment->id]);
});
