<?php

declare(strict_types=1);

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Filament\Pages\PurchasingDashboard;
use App\Filament\Widgets\PurchasingSpendTrend;
use App\Filament\Widgets\PurchasingStatistics;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
});

it('denies dashboard access without a purchasing permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(PurchasingDashboard::canAccess())->toBeFalse();
});

it('grants dashboard access with the order view permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(PurchasePermission::OrderView->value);

    $this->actingAs($user);

    expect(PurchasingDashboard::canAccess())->toBeTrue();
});

it('gates the statistics and spend-trend widgets behind the same permission', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(PurchasingStatistics::canView())->toBeFalse()
        ->and(PurchasingSpendTrend::canView())->toBeFalse();

    $user->givePermissionTo(PurchasePermission::OrderView->value);

    expect(PurchasingStatistics::canView())->toBeTrue()
        ->and(PurchasingSpendTrend::canView())->toBeTrue();
});

it('reports correct counts and this-month spend across purchase orders and confirmations', function (): void {
    // Open (non-terminal) orders.
    PurchaseOrder::factory()->count(2)->create();
    PurchaseOrder::factory()->count(3)->pendingApproval()->create();
    PurchaseOrder::factory()->approved()->create();
    PurchaseOrder::factory()->sent()->create();

    // Terminal orders, excluded from the open count.
    PurchaseOrder::factory()->received()->create();
    PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Closed, 'closed_at' => now()]);
    PurchaseOrder::factory()->cancelled()->create();

    // This-month spend: two orders dated this month with a non-zero amount,
    // and one dated last month that must be excluded from the sum.
    PurchaseOrder::factory()->create(['ordered_at' => now()->toDateString(), 'total_amount' => '1000.00']);
    PurchaseOrder::factory()->create(['ordered_at' => now()->toDateString(), 'total_amount' => '500.50']);
    PurchaseOrder::factory()->create(['ordered_at' => now()->subMonth()->startOfMonth()->toDateString(), 'total_amount' => '999.00']);

    SupplierConfirmation::factory()->count(2)->create();
    SupplierConfirmation::factory()->confirmed()->create();
    SupplierConfirmation::factory()->rejected()->create();

    $widget = app(PurchasingStatistics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);
    $values = array_map(fn ($stat): mixed => $stat->getValue(), $stats);

    expect($values)->toBe([10, 3, 2, '1,500.50']);
});

it('uses a bar chart for the spend trend', function (): void {
    $widget = app(PurchasingSpendTrend::class);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('bar');
});

it('buckets PO spend by month for the trailing six months', function (): void {
    PurchaseOrder::factory()->create(['ordered_at' => Carbon::now()->startOfMonth()->toDateString(), 'total_amount' => '100.00']);
    PurchaseOrder::factory()->create(['ordered_at' => Carbon::now()->startOfMonth()->toDateString(), 'total_amount' => '50.00']);
    PurchaseOrder::factory()->create(['ordered_at' => Carbon::now()->startOfMonth()->subMonths(2)->toDateString(), 'total_amount' => '75.00']);
    PurchaseOrder::factory()->create(['ordered_at' => Carbon::now()->startOfMonth()->subMonths(9)->toDateString(), 'total_amount' => '999.00']);

    $widget = app(PurchasingSpendTrend::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect($data['labels'])->toHaveCount(6)
        ->and($data['labels'][5])->toBe(Carbon::now()->startOfMonth()->format('M Y'))
        ->and($data['datasets'][0]['label'])->toBe('PO spend')
        ->and($data['datasets'][0]['data'][5])->toBe(150.0)
        ->and($data['datasets'][0]['data'][3])->toBe(75.0)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(225.0);
});
