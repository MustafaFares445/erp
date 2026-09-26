<?php

declare(strict_types=1);

use App\Data\Inventory\PriceFloorOverrideData;
use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\ProductStatus;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Models\CustomerProfile;
use App\Models\PriceFloorOverride;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Inventory\ProductPricingService;
use App\Services\Sales\QuotationService;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
    (new CrmPermissionSeeder)->run();
});

function salesOfficer(): User
{
    $officer = User::factory()->admin()->create();
    $officer->assignRole(DashboardRole::SalesOfficer->value);

    return $officer;
}

function floorOverrideApprover(): User
{
    $approver = User::factory()->admin()->create();
    $approver->assignRole(DashboardRole::SalesOfficer->value);
    $approver->givePermissionTo(InventoryPermission::PriceFloorApprove->value);

    return $approver;
}

it('still refuses a below-floor price from someone who cannot approve a price-floor override', function (): void {
    $officer = salesOfficer();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 90, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    Livewire::actingAs($officer)
        ->test(CreateQuotation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'issue_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'quantity' => 1,
                'use_tier_price' => false,
                'unit_price' => 80,
                'price_floor_override_reason' => 'I need this cheaper',
            ]],
        ])
        ->call('create');

    expect(Quotation::query()->count())->toBe(0)
        ->and(PriceFloorOverride::query()->count())->toBe(0);
});

it('lets an authorized approver save a below-floor line by entering a reason', function (): void {
    $approver = floorOverrideApprover();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 90, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    Livewire::actingAs($approver)
        ->test(CreateQuotation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'issue_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'quantity' => 1,
                'use_tier_price' => false,
                'unit_price' => 80,
                'price_floor_override_reason' => 'Strategic customer discount',
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $quotation = Quotation::query()->sole();
    $line = $quotation->lines->sole();

    expect((float) $line->unit_price)->toBe(80.0)
        ->and($line->price_floor_override_id)->not->toBeNull();

    $override = PriceFloorOverride::query()->sole();
    expect((float) $override->attempted_price)->toBe(80.0)
        ->and($override->approved_by)->toBe($approver->getKey())
        ->and($override->reason)->toBe('Strategic customer discount');
});

it('reuses the standing approval on a later edit that leaves the price unchanged', function (): void {
    $approver = floorOverrideApprover();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 90, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    // The override has to be minted by an approver before the line can be
    // saved at all (FR-016) — there is no unapproved intermediate state.
    $override = app(ProductPricingService::class)->approveFloorOverride(
        new PriceFloorOverrideData($variant->getKey(), $customer->user_id, 80, 'Pre-approved'),
        $approver,
    );
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 80, 'price_floor_override_id' => $override->getKey()]],
    );

    expect(Quotation::query()->count())->toBe(1)
        ->and(PriceFloorOverride::query()->count())->toBe(1);

    // A rep who cannot approve the override still re-saves the same
    // already-priced draft freely, because no *new* breach is being
    // requested — the standing override already covers this exact price.
    $officer = salesOfficer();

    $test = Livewire::actingAs($officer)
        ->test(EditQuotation::class, ['record' => $quotation->getKey()]);
    $lineKey = array_key_first($test->get('data.lines'));

    expect($test->get('data.lines.'.$lineKey.'.price_floor_override_id'))->toBe($override->getKey());

    $test->call('save')->assertHasNoFormErrors();

    expect($quotation->refresh()->lines->sole()->price_floor_override_id)->toBe($override->getKey())
        ->and(PriceFloorOverride::query()->count())->toBe(1);
});
