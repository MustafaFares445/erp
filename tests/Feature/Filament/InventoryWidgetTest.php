<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Widgets\InventoryLowStock;
use App\Filament\Widgets\InventoryPendingDocuments;
use App\Filament\Widgets\InventoryRecentMovements;
use App\Filament\Widgets\InventoryStockValue;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\StockTransfer;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('renders low-stock and recent-movement widget tables for authorized viewers', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        InventoryPermission::StockView->value,
        InventoryPermission::MovementView->value,
    ]);
    $lowStock = InventoryStock::factory()->create([
        'available_quantity' => 2,
        'reorder_level' => 3,
    ]);
    $healthyStock = InventoryStock::factory()->create([
        'available_quantity' => 5,
        'reorder_level' => 3,
    ]);
    $movement = InventoryMovement::factory()->create();

    Livewire::actingAs($viewer)
        ->test(InventoryLowStock::class)
        ->assertCanSeeTableRecords([$lowStock])
        ->assertCanNotSeeTableRecords([$healthyStock]);

    Livewire::actingAs($viewer)
        ->test(InventoryRecentMovements::class)
        ->assertCanSeeTableRecords([$movement]);
});

it('shows pending document counts for either source permission', function (): void {
    InventoryAdjustment::factory()->create(['status' => 'draft']);
    StockTransfer::factory()->create(['status' => 'draft']);
    $adjustmentViewer = User::factory()->create();
    $adjustmentViewer->givePermissionTo(InventoryPermission::AdjustmentView->value);

    $transferViewer = User::factory()->create();
    $transferViewer->givePermissionTo(InventoryPermission::TransferView->value);

    expect(InventoryPendingDocuments::canView())->toBeFalse();

    $this->actingAs($adjustmentViewer);
    expect(InventoryPendingDocuments::canView())->toBeTrue();

    $this->actingAs($transferViewer);
    $widget = app(InventoryPendingDocuments::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect(InventoryPendingDocuments::canView())->toBeTrue()
        ->and($stats)->toHaveCount(2);
});

it('uses a bar chart for usable stock valuation', function (): void {
    $widget = app(InventoryStockValue::class);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('bar');
});
