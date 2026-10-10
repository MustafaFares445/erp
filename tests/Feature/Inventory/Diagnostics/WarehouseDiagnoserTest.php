<?php

declare(strict_types=1);

use App\Enums\InventoryCountStatus;
use App\Enums\InventoryDiagnosticSeverity;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\Warehouses\Pages\ViewWarehouse;
use App\Models\InventoryCount;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\Diagnostics\WarehouseDiagnoser;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo(array_map(static fn (InventoryPermission $permission): string => $permission->value, InventoryPermission::cases()));
    $this->actingAs($manager);
});

it('treats an inactive warehouse as a blocker and offers the edit page', function (): void {
    $warehouse = Warehouse::factory()->inactive()->create();

    $finding = app(WarehouseDiagnoser::class)->diagnose($warehouse)->find('warehouse.inactive');

    expect($finding?->severity)->toBe(InventoryDiagnosticSeverity::Blocker)
        ->and($finding?->action?->allowed)->toBeTrue()
        ->and($warehouse->fresh()->is_active)->toBeFalse();
});

it('reports an empty warehouse without claiming it was physically checked', function (): void {
    $warehouse = Warehouse::factory()->create(['latitude' => 24.7, 'longitude' => 46.7]);

    $diagnosis = app(WarehouseDiagnoser::class)->diagnose($warehouse);

    expect($diagnosis->codes())->toBe(['warehouse.empty'])
        ->and($diagnosis->limits)->toContain(__('inventory_view_ux.limits.recorded_balances'));
});

it('links straight to the only unavailable balance and lists several otherwise', function (): void {
    $warehouse = Warehouse::factory()->create(['latitude' => 24.7, 'longitude' => 46.7]);
    $stock = InventoryStock::factory()->for($warehouse)->create([
        'on_hand_quantity' => 4, 'reserved_quantity' => 4, 'available_quantity' => 0,
    ]);

    $single = app(WarehouseDiagnoser::class)->diagnose($warehouse)->find('warehouse.unavailable');

    expect($single?->title)->toBe(__('inventory_view_ux.rules.warehouse.unavailable.title', ['count' => 1]))
        ->and($single?->action?->url)->toBe(StockLevelResource::getUrl('view', ['record' => $stock]))
        ->and($single?->facts)->toHaveCount(1);

    InventoryStock::factory()->for($warehouse)->count(6)->create([
        'on_hand_quantity' => 2, 'reserved_quantity' => 2, 'available_quantity' => 0,
    ]);

    $many = app(WarehouseDiagnoser::class)->diagnose($warehouse)->find('warehouse.unavailable');

    expect($many?->facts)->toHaveCount(6)
        ->and($many?->facts[5])->toBe(__('inventory_view_ux.facts.and_more', ['count' => 2]));
});

it('counts waiting deliveries, inbound transfers and counts awaiting review', function (): void {
    $warehouse = Warehouse::factory()->create(['latitude' => 24.7, 'longitude' => 46.7]);
    InventoryOperation::factory()->delivery()->create(['stage' => OperationStage::Waiting, 'source_warehouse_id' => $warehouse->getKey()]);
    InventoryOperation::factory()->internalTransfer()->create(['stage' => OperationStage::InTransit, 'destination_warehouse_id' => $warehouse->getKey()]);
    InventoryCount::factory()->create(['warehouse_id' => $warehouse->getKey(), 'status' => InventoryCountStatus::PendingReview]);

    $diagnosis = app(WarehouseDiagnoser::class)->diagnose($warehouse);

    expect($diagnosis->codes())->toContain('warehouse.waiting_deliveries', 'warehouse.inbound_transfers', 'warehouse.counts_pending')
        ->and($diagnosis->find('warehouse.inbound_transfers')?->severity)->toBe(InventoryDiagnosticSeverity::Notice);
});

it('renders the panel and key figures on the warehouse page in Arabic', function (): void {
    $warehouse = Warehouse::factory()->inactive()->create();
    app()->setLocale('ar');

    Livewire::test(ViewWarehouse::class, ['record' => $warehouse->getKey()])
        ->assertOk()
        ->assertSee(__('inventory_view_ux.title'))
        ->assertSee(__('inventory_view_ux.metrics_title'))
        ->assertSee(__('inventory_view_ux.rules.warehouse.inactive.title'))
        ->assertSeeHtml('data-finding="warehouse.inactive"');
});
