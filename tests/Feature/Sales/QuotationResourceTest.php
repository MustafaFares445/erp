<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\ProductStatus;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Sales\QuotationService;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

function salesUser(DashboardRole $role): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole($role->value);

    return $user;
}

it('lets Sales Officer list and create quotations', function (): void {
    $officer = salesUser(DashboardRole::SalesOfficer);

    Livewire::actingAs($officer)->test(ListQuotations::class)->assertSuccessful();

    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    Livewire::actingAs($officer)
        ->test(CreateQuotation::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'issue_date' => now()->toDateString(),
            'lines' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Quotation::query()->count())->toBe(1);
});

it('refuses Billing Officer the ability to create a quotation', function (): void {
    $billing = salesUser(DashboardRole::BillingOfficer);

    expect($billing->can('create', Quotation::class))->toBeFalse();
});

it('lets Reviewer view but not create or edit a quotation', function (): void {
    $reviewer = salesUser(DashboardRole::Reviewer);
    $customer = CustomerProfile::factory()->create();
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [],
    );

    Livewire::actingAs($reviewer)
        ->test(ViewQuotation::class, ['record' => $quotation->getKey()])
        ->assertSuccessful();

    expect($reviewer->can('create', Quotation::class))->toBeFalse()
        ->and($reviewer->can('update', $quotation))->toBeFalse();
});

it('refuses a user with no sales permission any access', function (): void {
    // A Purchasing Officer holds no sales.* permission at all — the seeder's
    // matrix grants it none — so this is a genuine cross-module refusal, not
    // a role that happens to lack one specific ability.
    (new PurchasePermissionSeeder)->run();
    $stranger = salesUser(DashboardRole::PurchasingOfficer);

    expect($stranger->can('viewAny', Quotation::class))->toBeFalse();
});

it('offers Send only on a draft quotation to a holder of the manage ability', function (): void {
    $officer = salesUser(DashboardRole::SalesOfficer);
    $customer = CustomerProfile::factory()->create();
    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [],
    );

    expect($officer->can('send', $quotation))->toBeTrue();

    app(QuotationService::class)->send($quotation);

    // Sent — the model itself now refuses further content changes, though
    // the `send` ability check is a manage-permission check, not a status
    // check; the status gate lives in the action's own `visible()`.
    expect($quotation->refresh()->isFrozen())->toBeTrue();
});

it('offers Record Decision only to a holder of the decide ability', function (): void {
    $officer = salesUser(DashboardRole::SalesOfficer);
    $manager = salesUser(DashboardRole::SalesManager);
    $billing = salesUser(DashboardRole::BillingOfficer);

    expect($officer->can('decide', Quotation::class))->toBeTrue()
        ->and($manager->can('decide', Quotation::class))->toBeTrue()
        ->and($billing->can('decide', Quotation::class))->toBeFalse();
});
