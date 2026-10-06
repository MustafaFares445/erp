<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Pages\InventoryDashboard;
use App\Filament\Widgets\InventoryExpiringLots;
use App\Filament\Widgets\InventoryKeyMetrics;
use App\Filament\Widgets\InventoryLowStock;
use App\Filament\Widgets\InventoryMovementsTrend;
use App\Filament\Widgets\InventoryRecentMovements;
use App\Filament\Widgets\InventoryStockValue;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('registers the essential inventory widgets including the expiry work queue', function (): void {
    $widgets = new ReflectionMethod(InventoryDashboard::class, 'getDashboardWidgets')->invoke(new InventoryDashboard);

    expect($widgets)->toBe([
        InventoryKeyMetrics::class,
        [InventoryMovementsTrend::class, InventoryStockValue::class],
        [InventoryLowStock::class, InventoryExpiringLots::class],
        InventoryRecentMovements::class,
    ]);
});

it('gives the movements chart the full row when stock value pricing is hidden', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo([InventoryPermission::StockView->value, InventoryPermission::MovementView->value]);
    $this->actingAs($user);

    $resolved = new ReflectionMethod(InventoryDashboard::class, 'resolveDashboardWidgets')->invoke(new InventoryDashboard);

    expect($resolved[1])->toBeInstanceOf(WidgetConfiguration::class)
        ->and($resolved[1]->widget)->toBe(InventoryMovementsTrend::class)
        ->and($resolved[1]->getProperties())->toBe(['spansFullWidth' => true]);
});

it('renders for a viewer with stock view access, with a warehouse filter', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::StockView->value);

    $warehouse = Warehouse::factory()->create(['name' => 'Dubai Main']);

    $this->actingAs($user)
        ->get(InventoryDashboard::getUrl())
        ->assertOk()
        ->assertSeeText(__('admin.resources.inventory_dashboard'));

    Livewire::test(InventoryDashboard::class)
        ->set('filters.warehouseId', $warehouse->id)
        ->assertSet('filters.warehouseId', $warehouse->id);
});
