<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Pages\InventoryDashboard;
use App\Filament\Widgets\InventoryKeyMetrics;
use App\Filament\Widgets\InventoryLowStock;
use App\Filament\Widgets\InventoryMovementsTrend;
use App\Filament\Widgets\InventoryOperationsPipeline;
use App\Filament\Widgets\InventoryPendingDocuments;
use App\Filament\Widgets\InventoryRecentMovements;
use App\Filament\Widgets\ReconciliationStatus;
use App\Filament\Widgets\InventoryStockStatistics;
use App\Filament\Widgets\InventoryStockValue;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('registers the redesigned widget set in the intended reading order', function (): void {
    $widget = app(InventoryDashboard::class);
    $widgets = new ReflectionMethod($widget, 'getHeaderWidgets')->invoke($widget);

    expect($widgets)->toBe([
        InventoryKeyMetrics::class,
        ReconciliationStatus::class,
        InventoryOperationsPipeline::class,
        InventoryPendingDocuments::class,
        InventoryStockValue::class,
        InventoryMovementsTrend::class,
        InventoryLowStock::class,
        InventoryRecentMovements::class,
        InventoryStockStatistics::class,
    ]);
});

it('lays out the charts two per row on large screens', function (): void {
    $widget = app(InventoryDashboard::class);

    expect($widget->getHeaderWidgetsColumns())->toBe(['lg' => 2]);
});

it('renders for a viewer with stock view access', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::StockView->value);

    $this->actingAs($user)
        ->get(InventoryDashboard::getUrl())
        ->assertOk()
        ->assertSeeText(__('admin.resources.inventory_dashboard'));
});
