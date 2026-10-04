<?php

declare(strict_types=1);

use App\Enums\SalesPermission;
use App\Filament\Pages\ReportsCenter;
use App\Models\User;
use App\Reporting\ReportRegistry;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

it('discovers only report domains the current user can access', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(SalesPermission::ReportView->value);
    $this->actingAs($viewer);

    $domains = ReportRegistry::accessibleDomains($viewer);

    expect($domains)->toHaveCount(1)
        ->and($domains[0]['key'])->toBe('sales')
        ->and($domains[0]['reports'])->toHaveCount(9);

    $customerRevenue = collect($domains[0]['reports'])
        ->firstWhere('key', 'sales.customer_revenue');

    expect($customerRevenue)->not->toBeNull();

    $url = $customerRevenue->url();
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    expect($url)->not->toBeNull()
        ->and($query['reportType'] ?? null)->toBe('customer_revenue');
});

it('renders the reports center as an authorization-aware discovery hub', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(SalesPermission::ReportView->value);

    Livewire::actingAs($viewer)
        ->test(ReportsCenter::class)
        ->assertOk()
        ->assertSee(__('reporting.center.heading'))
        ->assertSee('Customer Revenue')
        ->set('search', 'revenue')
        ->assertSee('Customer Revenue')
        ->set('search', 'inventory')
        ->assertSee(__('reporting.states.no_matching_reports'));
});

it('denies the reports center when the user has no accessible report domain', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(ReportsCenter::canAccess())->toBeFalse()
        ->and(ReportRegistry::accessibleDomains($user))->toBe([]);
});
