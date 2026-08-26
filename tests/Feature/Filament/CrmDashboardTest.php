<?php

declare(strict_types=1);

use App\Enums\CrmPermission;
use App\Filament\Pages\CrmDashboard;
use App\Filament\Widgets\CrmCustomerGrowthTrend;
use App\Filament\Widgets\CrmStatistics;
use App\Models\CustomerProfile;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new CrmPermissionSeeder)->run();
});

it('gates the CRM dashboard behind the customer view permission', function (): void {
    $unauthorized = User::factory()->create();
    $this->actingAs($unauthorized);
    expect(CrmDashboard::canAccess())->toBeFalse();

    $authorized = User::factory()->create();
    $authorized->givePermissionTo(CrmPermission::CustomerView->value);
    $this->actingAs($authorized);
    expect(CrmDashboard::canAccess())->toBeTrue();
});

it('gates CrmStatistics and reports customer, pricing tier, price floor, and price change counts', function (): void {
    $unauthorized = User::factory()->create();
    $this->actingAs($unauthorized);
    expect(CrmStatistics::canView())->toBeFalse();

    $authorized = User::factory()->create();
    $authorized->givePermissionTo(CrmPermission::CustomerView->value);
    $this->actingAs($authorized);
    expect(CrmStatistics::canView())->toBeTrue();

    CustomerProfile::factory()->count(2)->create(['is_active' => true]);
    CustomerProfile::factory()->create(['is_active' => false]);

    PricingTier::factory()->create();
    PricingTier::factory()->inactive()->create();
    PricingTier::factory()->create(['valid_until' => now()->subDay()]);

    PriceFloorOverride::factory()->count(2)->create();

    PriceHistory::factory()->create(['created_at' => now()]);
    PriceHistory::factory()->create(['created_at' => now()->subMonths(2)]);

    $widget = app(CrmStatistics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);
    $values = array_map(fn ($stat): int|string => $stat->getValue(), $stats);

    expect($values)->toBe([2, 1, 2, 1]);
});

it('returns a line chart of new customers grouped by month for the trailing six months', function (): void {
    $widget = app(CrmCustomerGrowthTrend::class);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('line');

    CustomerProfile::factory()->create(['created_at' => now()]);
    CustomerProfile::factory()->create(['created_at' => now()->subMonths(2)]);
    CustomerProfile::factory()->create(['created_at' => now()->subMonths(7)]);

    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect($data['datasets'][0]['label'])->toBe('New customers')
        ->and($data['labels'])->toHaveCount(6)
        ->and($data['datasets'][0]['data'])->toHaveCount(6);

    $counts = $data['datasets'][0]['data'];

    expect(array_sum($counts))->toBe(2)
        ->and($counts[5])->toBe(1)
        ->and($counts[3])->toBe(1);
});
