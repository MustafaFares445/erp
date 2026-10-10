<?php

declare(strict_types=1);

use App\Enums\SalesPermission;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Support\UserGuideArticleResolver;
use App\Models\CustomerProfile;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('links the dashboard user menu to the configured standalone user guide', function (): void {
    Config::set('app.user_guide_url', 'https://docs.example.test/');

    $this->actingAs(User::factory()->create());

    $item = collect(Filament::getPanel('admin')->getUserMenuItems())
        ->first(static fn (Action $action): bool => $action->getName() === 'user-guide');

    expect($item)
        ->not->toBeNull()
        ->and($item->getUrl())
        ->toBe('https://docs.example.test/');
});

it('does not register the user guide as a Filament page', function (): void {
    expect(Route::has('filament.admin.pages.user-guide'))->toBeFalse();
});

it('resolves resource routes to stable user guide articles', function (): void {
    Config::set('app.user_guide_url', 'https://docs.example.test/guide/');

    expect(UserGuideArticleResolver::forRouteName(
        'filament.admin.resources.quotations.index',
        'sales',
        'en',
    ))->toBe('https://docs.example.test/guide/screens/sales/quotation');
});

it('resolves Arabic resource routes to the Arabic guide tree', function (): void {
    Config::set('app.user_guide_url', '/guide/');

    expect(UserGuideArticleResolver::forRouteName(
        'filament.admin.resources.purchase-orders.view',
        'vendors',
        'ar',
    ))->toBe('/guide/ar/screens/vendors/purchase-order');
});

it('resolves standalone dashboard pages to the module dashboard article', function (): void {
    Config::set('app.user_guide_url', '/guide/');

    expect(UserGuideArticleResolver::forRouteName(
        'filament.admin.pages.sales-dashboard',
        'sales',
        'en',
    ))->toBe('/guide/screens/sales/dashboard');
});

it('does not invent a contextual guide link for an unknown route', function (): void {
    expect(UserGuideArticleResolver::forRouteName(
        'filament.admin.pages.does-not-exist',
        'sales',
        'en',
    ))->toBeNull();
});

it('keeps contextual help after Sales dashboard filter updates and reset', function (string $locale): void {
    (new SalesPermissionSeeder)->run();
    Config::set('app.user_guide_url', '/guide/');
    app()->setLocale($locale);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(SalesPermission::QuotationView->value);

    $customer = CustomerProfile::factory()->create();
    $url = $locale === 'ar' ? '/guide/ar/screens/sales/dashboard' : '/guide/screens/sales/dashboard';

    Livewire::actingAs($viewer)
        ->test(SalesDashboard::class)
        ->set('filters.customerId', $customer->getKey())
        ->assertSeeHtml('href="'.$url.'"')
        ->callAction('resetFilters')
        ->assertSeeHtml('href="'.$url.'"');
})->with(['English' => 'en', 'Arabic' => 'ar']);

it('keeps quotation contextual help after selecting a customer', function (string $locale): void {
    (new SalesPermissionSeeder)->run();
    Config::set('app.user_guide_url', '/guide/');
    app()->setLocale($locale);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo([SalesPermission::QuotationView->value, SalesPermission::QuotationManage->value]);

    $customer = CustomerProfile::factory()->create();
    $url = $locale === 'ar' ? '/guide/ar/screens/sales/quotation' : '/guide/screens/sales/quotation';

    Livewire::actingAs($viewer)
        ->test(CreateQuotation::class)
        ->set('data.customer_id', $customer->getKey())
        ->assertSeeHtml('href="'.$url.'"');
})->with(['English' => 'en', 'Arabic' => 'ar']);
