<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Settings\CurrencyCatalogService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new CurrencySeeder)->run();
    (new SalesPermissionSeeder)->run();
});

function currencyTestAdmin(): User
{
    $admin = User::factory()->admin()->create();
    $admin->assignRole(DashboardRole::SystemAdmin->value);

    return $admin;
}

it("formats money in the configured default currency rather than Filament's usd fallback", function (): void {
    expect(app(CurrencyCatalogService::class)->defaultCode())->toBe('AED');

    Invoice::factory()->create(['total_amount' => '378.00']);

    Livewire::actingAs(currencyTestAdmin())
        ->test(ListInvoices::class)
        ->assertSuccessful()
        ->assertSee('AED')
        ->assertDontSee('$378.00');
});

it('follows the default currency row, so money is never hardcoded to one currency', function (): void {
    Currency::query()->where('code', 'AED')->update(['is_default' => false]);
    Currency::query()->where('code', 'EUR')->update(['is_default' => true, 'is_active' => true]);

    Invoice::factory()->create(['total_amount' => '378.00']);

    Livewire::actingAs(currencyTestAdmin())
        ->test(ListInvoices::class)
        ->assertSuccessful()
        ->assertSee('€');
});

it('applies the default currency to infolists as well as tables', function (): void {
    // Tables and schemas are configured by separate global hooks, so the
    // record view has to be covered independently of the list.
    $invoice = Invoice::factory()->create(['total_amount' => '378.00']);

    Livewire::actingAs(currencyTestAdmin())
        ->test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('AED')
        ->assertDontSee('$378.00');
});
