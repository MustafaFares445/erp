<?php

declare(strict_types=1);

use App\Data\Inventory\PriceFloorOverrideData;
use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\ProductStatus;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Models\CustomerProfile;
use App\Models\CustomerQuotationRequest;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\ProductPricingService;
use App\Services\Sales\QuotationConversionService;
use App\Services\Sales\QuotationResponseService;
use App\Services\Sales\QuotationService;
use Carbon\CarbonImmutable;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
    (new CrmPermissionSeeder)->run();
});

function viewQuotationOfficer(): User
{
    $officer = User::factory()->admin()->create();
    $officer->assignRole(DashboardRole::SalesOfficer->value);

    return $officer;
}

function viewQuotationManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole(DashboardRole::SalesManager->value);

    return $manager;
}

function activeVariant(array $attributes = []): ProductVariant
{
    $variant = ProductVariant::factory()->create([
        'base_price' => 100,
        'status' => ProductStatus::Active,
        ...$attributes,
    ]);
    $variant->product->update(['status' => ProductStatus::Active]);

    return $variant;
}

it('renders a draft quotation without the accepted callout or response history', function (): void {
    $officer = viewQuotationOfficer();
    $customer = CustomerProfile::factory()->create();
    $variant = activeVariant();
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
    );

    Livewire::actingAs($officer)
        ->test(ViewQuotation::class, ['record' => $quotation->getKey()])
        ->assertSuccessful()
        ->assertSee('Send')
        ->assertDontSee('Customer accepted this quotation')
        ->assertDontSee('Response history');
});

it('renders the accepted callout and Convert to Order for an accepted, unconverted quotation', function (): void {
    $manager = viewQuotationManager();
    $customer = CustomerProfile::factory()->create();
    $variant = activeVariant();
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
    );
    $sent = app(QuotationService::class)->send($quotation);
    app(QuotationResponseService::class)->accept($sent, CarbonImmutable::now(), null, null, $manager);

    Livewire::actingAs($manager)
        ->test(ViewQuotation::class, ['record' => $sent->getKey()])
        ->assertSuccessful()
        ->assertSee('Customer accepted this quotation')
        ->assertSee('Convert to Order')
        ->assertActionExists('convert');
});

it('does not offer another conversion once the quotation has been converted', function (): void {
    $manager = viewQuotationManager();
    $customer = CustomerProfile::factory()->create();
    $variant = activeVariant();
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
    );
    $sent = app(QuotationService::class)->send($quotation);
    app(QuotationResponseService::class)->accept($sent, CarbonImmutable::now(), null, null, $manager);
    $order = app(QuotationConversionService::class)->convert($sent->refresh());

    Livewire::actingAs($manager)
        ->test(ViewQuotation::class, ['record' => $sent->getKey()])
        ->assertSuccessful()
        ->assertActionHidden('convert')
        ->assertSee((string) $order->order_number);
});

it('only shows response history when a decision has been recorded', function (): void {
    $officer = viewQuotationOfficer();
    $customer = CustomerProfile::factory()->create();
    $variant = activeVariant();
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
    );
    $sent = app(QuotationService::class)->send($quotation);
    app(QuotationResponseService::class)->accept($sent, CarbonImmutable::now(), 'Approved by procurement.', null, $officer);

    Livewire::actingAs($officer)
        ->test(ViewQuotation::class, ['record' => $sent->getKey()])
        ->assertSuccessful()
        ->assertSee('Response history')
        ->assertSee('Approved by procurement.');
});

it('shows the linked customer quote request only when one exists', function (): void {
    $officer = viewQuotationOfficer();
    $customer = CustomerProfile::factory()->create();
    $variant = activeVariant();
    $withoutRequest = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
    );

    Livewire::actingAs($officer)
        ->test(ViewQuotation::class, ['record' => $withoutRequest->getKey()])
        ->assertDontSee('Linked quote request');

    $withRequest = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
    );
    CustomerQuotationRequest::factory()->create([
        'customer_id' => $customer->getKey(),
        'resulting_quotation_id' => $withRequest->getKey(),
    ]);

    Livewire::actingAs($officer)
        ->test(ViewQuotation::class, ['record' => $withRequest->getKey()])
        ->assertSee('Linked quote request');
});

it('renders the pricing tier and a manual override clearly, and formats quantities without trailing zeros', function (): void {
    $officer = viewQuotationOfficer();
    $customer = CustomerProfile::factory()->create();
    $tier = PricingTier::factory()->customerSpecific()->create([
        'name' => 'VIP Wholesale',
        'customer_user_id' => $customer->user_id,
        'discount_value' => 15,
    ]);
    $tierVariant = activeVariant();
    $overrideVariant = activeVariant();

    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [
            ['product_variant_id' => $tierVariant->getKey(), 'quantity' => 2],
            ['product_variant_id' => $overrideVariant->getKey(), 'quantity' => 1, 'unit_price' => 55],
        ],
    );

    $response = Livewire::actingAs($officer)
        ->test(ViewQuotation::class, ['record' => $quotation->getKey()])
        ->assertSuccessful()
        ->assertSee('VIP Wholesale')
        ->assertSee('Manual override')
        ->assertSee('2')
        ->assertDontSee('2.000000');

    expect($tier->exists)->toBeTrue();
});

it('renders an approved below-floor override with its approver, and does not warn on a floor-compliant price', function (): void {
    $approver = viewQuotationOfficer();
    $approver->givePermissionTo(InventoryPermission::PriceFloorApprove->value);
    $customer = CustomerProfile::factory()->create();
    $variant = activeVariant(['base_price' => 100, 'min_price' => 90]);
    $override = app(ProductPricingService::class)->approveFloorOverride(
        new PriceFloorOverrideData($variant->getKey(), $customer->user_id, 80, 'Strategic customer discount'),
        $approver,
    );
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 80, 'price_floor_override_id' => $override->getKey()]],
    );

    Livewire::actingAs($approver)
        ->test(ViewQuotation::class, ['record' => $quotation->getKey()])
        ->assertSuccessful()
        ->assertSee('Approved exception')
        ->assertSee($approver->name);

    $compliantVariant = activeVariant(['base_price' => 100, 'min_price' => 50]);
    $compliantQuotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $compliantVariant->getKey(), 'quantity' => 1]],
    );

    Livewire::actingAs($approver)
        ->test(ViewQuotation::class, ['record' => $compliantQuotation->getKey()])
        ->assertSuccessful()
        ->assertDontSee('Approved exception');
});

it('keeps the numeric totals shown on the redesigned page unchanged', function (): void {
    $officer = viewQuotationOfficer();
    $customer = CustomerProfile::factory()->create();
    $variant = activeVariant();
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 2, 'unit_price' => 100, 'tax_amount' => 10]],
    );
    $quotation->refresh();

    Livewire::actingAs($officer)
        ->test(ViewQuotation::class, ['record' => $quotation->getKey()])
        ->assertSuccessful();

    expect((float) $quotation->subtotal)->toBe(200.0)
        ->and((float) $quotation->tax_total)->toBe(10.0)
        ->and((float) $quotation->grand_total)->toBe(210.0);
});
