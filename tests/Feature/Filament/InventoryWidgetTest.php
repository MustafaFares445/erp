<?php

declare(strict_types=1);

use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Filament\Widgets\InventoryKeyMetrics;
use App\Filament\Widgets\InventoryLowStock;
use App\Filament\Widgets\InventoryMovementsTrend;
use App\Filament\Widgets\InventoryOperationsPipeline;
use App\Filament\Widgets\InventoryPendingDocuments;
use App\Filament\Widgets\InventoryRecentMovements;
use App\Filament\Widgets\InventoryStockStatistics;
use App\Filament\Widgets\InventoryStockValue;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAlert;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
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
    $outOfStockWithoutReorderLevel = InventoryStock::factory()->withoutReorderLevel()->create([
        'available_quantity' => 0,
    ]);
    $movement = InventoryMovement::factory()->create();

    Livewire::actingAs($viewer)
        ->test(InventoryLowStock::class)
        ->assertCanSeeTableRecords([$lowStock, $outOfStockWithoutReorderLevel])
        ->assertCanNotSeeTableRecords([$healthyStock]);

    Livewire::actingAs($viewer)
        ->test(InventoryRecentMovements::class)
        ->assertCanSeeTableRecords([$movement]);
});

it('shows pending document counts for either source permission', function (): void {
    InventoryAdjustment::factory()->create(['status' => 'draft']);
    InventoryOperation::factory()->internalTransfer()->draft()->create();
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

it('counts dispatched transfers awaiting receipt as pending, alongside drafts', function (): void {
    InventoryOperation::factory()->internalTransfer()->draft()->create();
    InventoryOperation::factory()->internalTransfer()->inTransit()->create();
    InventoryOperation::factory()->internalTransfer()->done()->create();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::TransferView->value);
    $this->actingAs($viewer);

    $widget = app(InventoryPendingDocuments::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats[1]->getValue())->toBe('2');
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
    InventoryOperationLine::factory()->for($inTransitOperation, 'operation')->create([
        'quantity' => '6.000',
        'dispatched_base_quantity' => '6.000000',
        'received_base_quantity' => '0.000000',
    ]);

    $draftOperation = InventoryOperation::factory()->internalTransfer()->draft()->create();
    InventoryOperationLine::factory()->for($draftOperation, 'operation')->create(['quantity' => '99.000']);

    $receiptInTransit = InventoryOperation::factory()->receipt()->create(['stage' => OperationStage::InTransit]);
    InventoryOperationLine::factory()->for($receiptInTransit, 'operation')->create(['quantity' => '50.000']);

    $widget = app(InventoryStockStatistics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);
    $values = array_map(fn ($stat): string => $stat->getValue(), $stats);

    expect($values)->toBe(['13.000', '5.000', '1.000', '7.000', '6.000']);
});

it('computes headline stock metrics for a stock-view-only viewer', function (): void {
    $variant = ProductVariant::factory()->create(['cost_price' => '10.00']);
    InventoryStock::factory()->create([
        'product_variant_id' => $variant->id,
        'on_hand_quantity' => 5,
        'available_quantity' => 5,
        'reorder_level' => 10,
    ]);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::StockView->value);
    $this->actingAs($viewer);

    $widget = app(InventoryKeyMetrics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats)->toHaveCount(3)
        ->and($stats[0]->getValue())->toBe(number_format(50.0, 2))
        ->and($stats[1]->getValue())->toBe('1')
        ->and($stats[2]->getValue())->toBe('1');
});

it('adds unresolved-alerts and awaiting-action stats once the viewer holds those permissions', function (): void {
    InventoryAlert::factory()->create(['severity' => InventoryAlertSeverity::Critical]);
    InventoryOperation::factory()->create();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        InventoryPermission::StockView->value,
        InventoryPermission::AlertView->value,
        InventoryPermission::ReceiptView->value,
    ]);
    $this->actingAs($viewer);

    $widget = app(InventoryKeyMetrics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats)->toHaveCount(5)
        ->and($stats[3]->getValue())->toBe('1')
        ->and($stats[4]->getValue())->toBe('1');
});

it('hides the key metrics widget from a viewer without stock view', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);

    expect(InventoryKeyMetrics::canView())->toBeFalse();
});

it('counts inventory operations per non-terminal stage', function (): void {
    InventoryOperation::factory()->draft()->create();
    InventoryOperation::factory()->draft()->create();
    InventoryOperation::factory()->waiting()->create();
    InventoryOperation::factory()->internalTransfer()->ready()->create();
    InventoryOperation::factory()->internalTransfer()->inTransit()->create();
    InventoryOperation::factory()->done()->create();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::ReceiptView->value);
    $this->actingAs($viewer);

    $widget = app(InventoryOperationsPipeline::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);
    $values = array_map(fn ($stat): string => $stat->getValue(), $stats);

    expect($values)->toBe(['2', '1', '1', '1', '0']);
});

it('hides the operations pipeline widget without any operation view permission', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);

    expect(InventoryOperationsPipeline::canView())->toBeFalse();
});

it('uses a line chart for the movements trend', function (): void {
    $widget = app(InventoryMovementsTrend::class);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('line');
});

it('splits todays movement quantity into inbound and outbound totals', function (): void {
    InventoryMovement::factory()->create(['quantity' => 8]);
    InventoryMovement::factory()->create(['quantity' => -3]);

    $widget = app(InventoryMovementsTrend::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect($data['datasets'][0]['data'][29])->toBe(8.0)
        ->and($data['datasets'][1]['data'][29])->toBe(3.0);
});

it('hides the movements trend widget without movement view', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);

    expect(InventoryMovementsTrend::canView())->toBeFalse();
});
