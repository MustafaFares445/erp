<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Filament\Support\UserGuideArticleResolver;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('uses the active locale for the global user guide menu link', function (string $locale, string $expected): void {
    Config::set('app.user_guide_url', 'https://docs.example.test/guide/');
    app()->setLocale($locale);
    $this->actingAs(User::factory()->create());

    $item = collect(Filament::getPanel('admin')->getUserMenuItems())
        ->first(static fn (Action $action): bool => $action->getName() === 'user-guide');

    expect(UserGuideArticleResolver::homeUrl($locale))->toBe($expected)
        ->and($item?->getUrl())->toBe($expected);
})->with([
    'English' => ['en', 'https://docs.example.test/guide/'],
    'Arabic' => ['ar', 'https://docs.example.test/guide/ar/'],
]);

it('searches quotations by customer company name and code in SQLite', function (): void {
    (new SalesPermissionSeeder)->run();
    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::SalesOfficer->value);

    $customer = CustomerProfile::factory()->create([
        'company_name' => 'Agent Seven Search Customer',
        'customer_code' => 'A7-SEARCH-2608',
    ]);
    $quote = Quotation::factory()->create(['customer_id' => $customer->getKey()]);

    Livewire::actingAs($user)->test(ListQuotations::class)
        ->searchTable('Agent Seven')
        ->assertCanSeeTableRecords([$quote])
        ->searchTable('A7-SEARCH-2608')
        ->assertCanSeeTableRecords([$quote])
        ->searchTable('AGENT7-NOT-FOUND')
        ->assertCanNotSeeTableRecords([$quote]);
});

it('shows expired sent quotation status and blocks a late decision', function (): void {
    (new SalesPermissionSeeder)->run();
    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::SalesOfficer->value);

    $quote = Quotation::factory()->create([
        'status' => QuotationStatus::Sent,
        'expires_at' => now()->subDays(3)->toDateString(),
    ]);

    expect($quote->fresh()->isExpired())->toBeTrue();

    Livewire::actingAs($user)->test(ViewQuotation::class, ['record' => $quote->getKey()])
        ->assertSuccessful()
        ->assertActionHidden('record_decision')
        ->assertActionVisible('requote')
        ->assertSee(QuotationStatus::Expired->label());
});

it('retains the accepted customer decision after a quotation has become an order', function (): void {
    (new SalesPermissionSeeder)->run();
    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::SalesOfficer->value);

    $customer = CustomerProfile::factory()->create();
    $order = Order::factory()->create(['customer_id' => $customer->getKey()]);
    $quote = Quotation::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => QuotationStatus::ConvertedToOrder,
        'converted_order_id' => $order->getKey(),
        'decided_at' => now()->toDateString(),
    ]);

    Livewire::actingAs($user)->test(ViewQuotation::class, ['record' => $quote->getKey()])
        ->assertSuccessful()
        ->assertSee(__('Customer decision'))
        ->assertSee(QuotationStatus::Accepted->label())
        ->assertSee(QuotationStatus::ConvertedToOrder->label());
});
