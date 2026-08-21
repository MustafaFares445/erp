<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Filament\Widgets\InventoryLowStock;
use App\Filament\Widgets\InventoryPendingDocuments;
use App\Filament\Widgets\InventoryRecentMovements;
use App\Filament\Widgets\InventoryStockStatistics;
use App\Filament\Widgets\InventoryStockValue;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
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

it('reports stock totals and in-transit quantity across all warehouses', function (): void {
    InventoryStock::factory()->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '4.000',
        'damaged_quantity' => '1.000',
        'available_quantity' => '5.000',
    ]);
    InventoryStock::factory()->create([
        'on_hand_quantity' => '3.000',
        'reserved_quantity' => '1.000',
        'damaged_quantity' => '0.000',
        'available_quantity' => '2.000',
    ]);

    $inTransitOperation = InventoryOperation::factory()->internalTransfer()->inTransit()->create();
    InventoryOperationLine::factory()->for($inTransitOperation, 'operation')->create(['quantity' => '6.000']);

    $draftOperation = InventoryOperation::factory()->internalTransfer()->draft()->create();
    InventoryOperationLine::factory()->for($draftOperation, 'operation')->create(['quantity' => '99.000']);

    $receiptInTransit = InventoryOperation::factory()->receipt()->create(['stage' => OperationStage::InTransit]);
    InventoryOperationLine::factory()->for($receiptInTransit, 'operation')->create(['quantity' => '50.000']);

    $widget = app(InventoryStockStatistics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);
    $values = array_map(fn ($stat): string => $stat->getValue(), $stats);

    expect($values)->toBe(['13.000', '5.000', '1.000', '7.000', '6.000']);
});
