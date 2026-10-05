<?php

declare(strict_types=1);

use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Enums\MovementType;
use App\Enums\OperationStage;
use App\Enums\ReconciliationScope;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryReports\Pages\ManageInventoryReports;
use App\Filament\Resources\InventoryReports\Widgets\InventoryQuarantineAgeing;
use App\Filament\Resources\InventoryReports\Widgets\ReconciliationStatus;
use App\Filament\Widgets\InventoryKeyMetrics;
use App\Filament\Widgets\InventoryLowStock;
use App\Filament\Widgets\InventoryMovementsTrend;
use App\Filament\Widgets\InventoryRecentMovements;
use App\Filament\Widgets\InventoryStockStatistics;
use App\Filament\Widgets\InventoryStockValue;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAlert;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\ReconciliationRun;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use App\Support\MoneyFormatter;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('keeps every inventory report in its category and selects the first report when changing category', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(array_map(static fn (InventoryPermission $permission): string => $permission->value, InventoryPermission::cases()));
    $component = Livewire::actingAs($viewer)->test(ManageInventoryReports::class);
    $component->set('activeTab', 'stock_availability')->assertSet('report', InventoryReportType::StockLevels->value);
    $page = $component->instance();
    foreach (InventoryReportType::cases() as $type) {
        $expected = match ($type) {
            InventoryReportType::StockLevels, InventoryReportType::Devices, InventoryReportType::ExpiryLots, InventoryReportType::QuarantineAgeing => 'stock_availability',
            InventoryReportType::Movements, InventoryReportType::ConditionChanges, InventoryReportType::CountVariance, InventoryReportType::Reconciliation => 'movements_control',
            InventoryReportType::Catalog, InventoryReportType::SupplierComparison => 'catalog_suppliers',
            InventoryReportType::PriceHistory, InventoryReportType::PricingTiers, InventoryReportType::CustomerAssignments, InventoryReportType::FloorOverrides => 'pricing',
            InventoryReportType::ImportRuns, InventoryReportType::ImportResults => 'imports',
        };
        $page->report = $type->value;
        expect($page->getDefaultActiveTab())->toBe($expected);
    }
});

it('renders low-stock and recent-movement widget tables for authorized viewers', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        InventoryPermission::StockView->value,
        InventoryPermission::MovementView->value,
    ]);
    $lowStock = InventoryStock::factory()->create([
        'available_quantity' => 2,
    ]);
    WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $lowStock->warehouse_id,
        'product_variant_id' => $lowStock->product_variant_id,
        'min_quantity' => 3,
    ]);
    $healthyStock = InventoryStock::factory()->create([
        'available_quantity' => 5,
    ]);
    WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $healthyStock->warehouse_id,
        'product_variant_id' => $healthyStock->product_variant_id,
        'min_quantity' => 3,
    ]);
    $outOfStockWithoutPolicy = InventoryStock::factory()->create([
        'available_quantity' => 0,
    ]);
    $movement = InventoryMovement::factory()->create();

    Livewire::actingAs($viewer)
        ->test(InventoryLowStock::class)
        ->assertCanSeeTableRecords([$lowStock, $outOfStockWithoutPolicy])
        ->assertCanNotSeeTableRecords([$healthyStock]);

    Livewire::actingAs($viewer)
        ->test(InventoryRecentMovements::class)
        ->assertCanSeeTableRecords([$movement]);
});

it('counts open operations and draft adjustments as awaiting action', function (): void {
    InventoryAdjustment::factory()->create(['status' => 'draft']);
    InventoryOperation::factory()->internalTransfer()->draft()->create();
    InventoryOperation::factory()->internalTransfer()->inTransit()->create();
    InventoryOperation::factory()->internalTransfer()->done()->create();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo([InventoryPermission::StockView->value, InventoryPermission::TransferView->value]);
    $this->actingAs($viewer);

    $widget = app(InventoryKeyMetrics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats)->toHaveCount(3)
        ->and($stats[2]->getValue())->toBe('3')
        ->and((string) $stats[2]->getDescription())->toBe('1 draft adjustments · 2 open transfers')
        ->and($stats[2]->getColor())->toBe('warning');
});

it('narrows the key metrics and tables to the selected warehouse', function (): void {
    $variant = ProductVariant::factory()->create(['cost_price' => '10.00']);
    $kept = InventoryStock::factory()->create(['product_variant_id' => $variant->id, 'on_hand_quantity' => 0, 'available_quantity' => 0]);
    $other = InventoryStock::factory()->create(['product_variant_id' => $variant->id, 'on_hand_quantity' => 7, 'available_quantity' => 7]);
    InventoryAdjustment::factory()->create(['status' => 'draft', 'warehouse_id' => $other->warehouse_id]);
    InventoryOperation::factory()->internalTransfer()->draft()->create([
        'source_warehouse_id' => $other->warehouse_id,
        'destination_warehouse_id' => Warehouse::factory()->create()->id,
    ]);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        InventoryPermission::StockView->value,
        InventoryPermission::MovementView->value,
        InventoryPermission::AdjustmentView->value,
    ]);
    $this->actingAs($viewer);

    $widget = app(InventoryKeyMetrics::class);
    $widget->pageFilters = ['warehouseId' => $kept->warehouse_id];

    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats[0]->getValue())->toBe(MoneyFormatter::formatAmount(0))
        ->and($stats[1]->getValue())->toBe('1')
        ->and($stats[2]->getValue())->toBe('0')
        ->and($stats[2]->getColor())->toBe('success');

    $keptMovement = InventoryMovement::factory()->create(['warehouse_id' => $kept->warehouse_id]);
    $otherMovement = InventoryMovement::factory()->create(['warehouse_id' => $other->warehouse_id]);

    Livewire::test(InventoryRecentMovements::class, ['pageFilters' => ['warehouseId' => $kept->warehouse_id]])
        ->assertCanSeeTableRecords([$keptMovement])
        ->assertCanNotSeeTableRecords([$otherMovement]);

    Livewire::test(InventoryLowStock::class, ['pageFilters' => ['warehouseId' => $other->warehouse_id]])
        ->assertCanNotSeeTableRecords([$kept]);
});

it('shows unresolved alerts in place of replenishment for a viewer without replenishment access', function (): void {
    InventoryAlert::factory()->create(['severity' => InventoryAlertSeverity::Warning]);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo([InventoryPermission::StockView->value, InventoryPermission::AlertView->value]);
    $this->actingAs($viewer);

    $widget = app(InventoryKeyMetrics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats)->toHaveCount(3)
        ->and($stats[2]->getValue())->toBe('1')
        ->and($stats[2]->getColor())->toBe('warning');
});

it('labels stock value by warehouse in the default currency and filters by warehouse', function (): void {
    $variant = ProductVariant::factory()->create(['cost_price' => '2.00']);
    $first = InventoryStock::factory()->create(['product_variant_id' => $variant->id, 'available_quantity' => 5]);
    InventoryStock::factory()->create(['product_variant_id' => $variant->id, 'available_quantity' => 3]);

    $widget = app(InventoryStockValue::class);
    $widget->pageFilters = ['warehouseId' => $first->warehouse_id];

    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect($data['datasets'][0]['label'])->toStartWith('Stock value (')
        ->and($data['datasets'][0]['data'])->toBe([10.0]);
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

    expect($values)->toBe(['13', '5', '1', '7', '6']);
});

it('computes headline stock metrics for a stock-view-only viewer', function (): void {
    $variant = ProductVariant::factory()->create(['cost_price' => '10.00']);
    $stock = InventoryStock::factory()->create([
        'product_variant_id' => $variant->id,
        'on_hand_quantity' => 5,
        'available_quantity' => 5,
    ]);
    WarehouseReplenishmentPolicy::factory()->create([
        'warehouse_id' => $stock->warehouse_id,
        'product_variant_id' => $stock->product_variant_id,
        'min_quantity' => 10,
    ]);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::StockView->value);
    $this->actingAs($viewer);

    $widget = app(InventoryKeyMetrics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats)->toHaveCount(2)
        ->and($stats[0]->getValue())->toBe(MoneyFormatter::formatAmount(50))
        ->and((string) $stats[0]->getDescription())->toBe('1 stocked SKUs across 1 warehouses')
        ->and($stats[1]->getValue())->toBe('1');
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

    expect($stats)->toHaveCount(4)
        ->and($stats[2]->getValue())->toBe('1')
        ->and($stats[2]->getColor())->toBe('danger')
        ->and($stats[3]->getValue())->toBe('1');
});

it('hides the key metrics widget from a viewer without stock view', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);

    expect(InventoryKeyMetrics::canView())->toBeFalse();
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

it('skips movement rows whose timestamp falls outside the precomputed trailing window', function (): void {
    InventoryMovement::factory()->create(['quantity' => 9, 'created_at' => now()->addDays(5)]);

    $widget = app(InventoryMovementsTrend::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect(array_sum($data['datasets'][0]['data']))->toBe(0.0)
        ->and(array_sum($data['datasets'][1]['data']))->toBe(0.0);
});

it('hides the movements trend widget without movement view', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);

    expect(InventoryMovementsTrend::canView())->toBeFalse();
});

it('renders correction movements in the recent movements widget', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::MovementView->value);

    $movement = InventoryMovement::factory()->create([
        'movement_type' => MovementType::Correction,
        'quantity' => '-2.000000',
        'base_quantity_delta' => '-2.000000',
    ]);

    Livewire::actingAs($viewer)
        ->test(InventoryRecentMovements::class)
        ->assertCanSeeTableRecords([$movement])
        ->assertSee('Correction')
        ->assertSee('-2')
        ->assertDontSee('-2.000000');
});

it('shows inventory reconciliation as not run when only other scopes have runs', function (): void {
    ReconciliationRun::query()->create([
        'scope' => ReconciliationScope::Receivables,
        'invariant' => 'ledger_equals_subledger',
        'passed' => false,
        'divergence_count' => 1,
        'started_at' => now(),
        'finished_at' => now(),
        'trigger_source' => 'manual',
    ]);

    $widget = app(ReconciliationStatus::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats)->toHaveCount(1)
        ->and($stats[0]->getValue())->toBe('Not run')
        ->and($stats[0]->getColor())->toBe('warning');
});

it('summarises only the latest reconciliation run when one of its checks failed', function (): void {
    $this->freezeTime();

    foreach ([
        ['invariant' => 'aggregate_equals_lot_sum', 'passed' => false, 'divergence_count' => 9, 'finished_at' => now()->subDay()],
        ['invariant' => 'aggregate_equals_lot_sum', 'passed' => false, 'divergence_count' => 2, 'finished_at' => now()->subHours(2)],
        ['invariant' => 'reserved_equals_allocations', 'passed' => true, 'divergence_count' => 0, 'finished_at' => now()->subHours(2)],
    ] as $run) {
        ReconciliationRun::query()->create([...$run, 'scope' => ReconciliationScope::InventoryLots, 'started_at' => $run['finished_at'], 'trigger_source' => 'schedule']);
    }

    $widget = app(ReconciliationStatus::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats)->toHaveCount(1)
        ->and($stats[0]->getValue())->toBe('Fail')
        ->and($stats[0]->getDescription())->toBe('1 of 2 checks failed, 2 divergences · finished 2 hours ago')
        ->and($stats[0]->getColor())->toBe('danger');
});

it('reports a pass when every check in the latest reconciliation run passed', function (): void {
    $this->freezeTime();

    foreach ([
        ['invariant' => 'aggregate_equals_lot_sum', 'passed' => false, 'divergence_count' => 3, 'finished_at' => now()->subDay()],
        ['invariant' => 'aggregate_equals_lot_sum', 'passed' => true, 'divergence_count' => 0, 'finished_at' => now()->subHours(2)],
        ['invariant' => 'reserved_equals_allocations', 'passed' => true, 'divergence_count' => 0, 'finished_at' => now()->subHours(2)],
    ] as $run) {
        ReconciliationRun::query()->create([...$run, 'scope' => ReconciliationScope::InventoryLots, 'started_at' => $run['finished_at'], 'trigger_source' => 'schedule']);
    }

    $widget = app(ReconciliationStatus::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats[0]->getValue())->toBe('Pass')
        ->and($stats[0]->getDescription())->toBe('All 2 checks passed · finished 2 hours ago')
        ->and($stats[0]->getColor())->toBe('success');
});

it('shows the reconciliation status only to users who can open the reconciliation report', function (): void {
    $movementViewer = User::factory()->create();
    $movementViewer->givePermissionTo(InventoryPermission::MovementView->value);
    $this->actingAs($movementViewer);

    expect(ReconciliationStatus::canView())->toBeFalse();

    $stockViewer = User::factory()->create();
    $stockViewer->givePermissionTo(InventoryPermission::StockView->value);
    $this->actingAs($stockViewer);

    expect(ReconciliationStatus::canView())->toBeTrue();
});

it('ages quarantined stock using the timeline of its oldest matching quarantine movement', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::StockView->value);
    $this->actingAs($viewer);

    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Quarantine,
        'on_hand_base_quantity' => '3.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition_to' => StockCondition::Quarantine,
        'created_at' => now()->subDays(40),
    ]);

    $widget = app(InventoryQuarantineAgeing::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect($stats[0]->getValue())->toBe('1');
});

it('shows quarantined stock aged over thirty days with total quantity', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::StockView->value);
    $this->actingAs($viewer);

    $old = InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
        'stock_condition' => StockCondition::Quarantine,
        'on_hand_base_quantity' => '4.500000',
        'reserved_base_quantity' => '0.000000',
        'created_at' => now()->subDays(45),
        'updated_at' => now()->subDays(45),
    ]);
    InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
        'stock_condition' => StockCondition::Quarantine,
        'on_hand_base_quantity' => '7.000000',
        'reserved_base_quantity' => '0.000000',
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subDays(5),
    ]);

    $widget = app(InventoryQuarantineAgeing::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);

    expect(InventoryQuarantineAgeing::canView())->toBeTrue()
        ->and($stats)->toHaveCount(1)
        ->and($stats[0]->getValue())->toBe('1')
        ->and($stats[0]->getDescription())->toContain('4.5');

    expect($old->fresh()?->on_hand_base_quantity)->toBe('4.500000');
});

it('shows each inventory report summary card only on its own report tab', function (string $report, array $shown, array $hidden): void {
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo([
        InventoryPermission::ReportView->value,
        InventoryPermission::StockView->value,
    ]);

    $page = Livewire::actingAs($viewer)
        ->withQueryParams(['report' => $report])
        ->test(ManageInventoryReports::class)
        ->assertSuccessful();

    foreach ($shown as $widget) {
        $page->assertSeeLivewire($widget);
    }

    foreach ($hidden as $widget) {
        $page->assertDontSeeLivewire($widget);
    }
})->with([
    'reconciliation' => ['reconciliation', [ReconciliationStatus::class], [InventoryQuarantineAgeing::class]],
    'quarantine ageing' => ['quarantine_ageing', [InventoryQuarantineAgeing::class], [ReconciliationStatus::class]],
    'other reports' => ['stock_levels', [], [ReconciliationStatus::class, InventoryQuarantineAgeing::class]],
]);
