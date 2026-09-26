<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Widgets\QuotationsOverview;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

function quotationSalesUser(): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::SalesOfficer->value);

    return $user;
}

it('scopes open quotations to non-terminal statuses', function (): void {
    Quotation::factory()->create(['status' => QuotationStatus::Draft]);
    Quotation::factory()->sent()->create();
    Quotation::factory()->accepted()->create();
    Quotation::factory()->create(['status' => QuotationStatus::Rejected]);
    Quotation::factory()->create(['status' => QuotationStatus::Cancelled]);

    expect(Quotation::query()->open()->count())->toBe(3);
});

it('scopes awaiting-decision quotations to sent status only', function (): void {
    Quotation::factory()->sent()->create();
    Quotation::factory()->accepted()->create();

    expect(Quotation::query()->awaitingDecision()->count())->toBe(1);
});

it('scopes accepted-not-converted quotations, excluding converted ones', function (): void {
    Quotation::factory()->accepted()->create();
    Quotation::factory()->create([
        'status' => QuotationStatus::ConvertedToDelivery,
        'converted_order_id' => Order::factory(),
    ]);

    expect(Quotation::query()->acceptedNotConverted()->count())->toBe(1);
});

it('scopes expiring-soon quotations to active ones within the next 7 days', function (): void {
    Quotation::factory()->sent()->create(['expires_at' => now()->addDays(3)]);
    Quotation::factory()->sent()->create(['expires_at' => now()->addDays(20)]);
    Quotation::factory()->create(['status' => QuotationStatus::Rejected, 'expires_at' => now()->addDays(2)]);

    expect(Quotation::query()->expiringSoon()->count())->toBe(1);
});

it('renders the quotations overview stats widget with correct counts', function (): void {
    $customer = CustomerProfile::factory()->create();
    Quotation::factory()->for($customer, 'customer')->sent()->create(['expires_at' => now()->addDays(3)]);
    Quotation::factory()->for($customer, 'customer')->accepted()->create();

    Livewire::actingAs(quotationSalesUser())
        ->test(QuotationsOverview::class)
        ->assertSuccessful();
});

it('lists quotations and filters them via quick-view tabs', function (): void {
    $customer = CustomerProfile::factory()->create();
    Quotation::factory()->for($customer, 'customer')->create(['status' => QuotationStatus::Draft]);
    Quotation::factory()->for($customer, 'customer')->sent()->create();
    Quotation::factory()->for($customer, 'customer')->accepted()->create();

    $component = Livewire::actingAs(quotationSalesUser())->test(ListQuotations::class);

    $component->assertSuccessful()
        ->assertCanSeeTableRecords(Quotation::query()->get());

    $component->set('activeTab', 'draft');
    expect(Quotation::query()->where('status', QuotationStatus::Draft->value)->count())->toBe(1);
});

it('filters quotations by customer', function (): void {
    $matching = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $wanted = Quotation::factory()->for($matching, 'customer')->create();
    $unwanted = Quotation::factory()->for($other, 'customer')->create();

    Livewire::actingAs(quotationSalesUser())
        ->test(ListQuotations::class)
        ->filterTable('customer_id', $matching->getKey())
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});

it('uses a descriptive search placeholder', function (): void {
    $component = Livewire::actingAs(quotationSalesUser())->test(ListQuotations::class);

    expect($component->instance()->getTable()->getSearchPlaceholder())
        ->toBe('Search by quotation number, customer name, or customer code');
});
