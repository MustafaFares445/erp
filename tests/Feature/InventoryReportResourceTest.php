<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Filament\Resources\InventoryReports\InventoryReportResource;
use App\Filament\Resources\InventoryReports\Pages\ManageInventoryReports;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('shows only reports allowed by report and source permissions', function (): void {
    $viewer = reportViewer([
        InventoryPermission::CatalogView,
        InventoryPermission::StockView,
    ]);
    $component = Livewire::actingAs($viewer)->test(ManageInventoryReports::class)->assertOk();
    $tabs = $component->instance()->getTabs();

    expect(array_keys($tabs))->toBe([
        InventoryReportType::Catalog->value,
        InventoryReportType::StockLevels->value,
        InventoryReportType::Devices->value,
        InventoryReportType::ExpiryLots->value,
    ]);

    $component
        ->assertTableColumnVisible('sku')
        ->assertTableColumnDoesNotExist('cost_price');
});

it('uses the shared query filters when switching report tabs', function (): void {
    $viewer = reportViewer([InventoryPermission::StockView]);
    $warehouse = Warehouse::factory()->create();
    $matching = InventoryStock::factory()->for($warehouse)->create();
    $other = InventoryStock::factory()->create();

    Livewire::actingAs($viewer)
        ->test(ManageInventoryReports::class)
        ->set('activeTab', InventoryReportType::StockLevels->value)
        ->filterTable('warehouse_id', $warehouse->getKey())
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other])
        ->assertTableColumnVisible('damaged_quantity')
        ->assertTableColumnDoesNotExist('usable_value');
});

it('reveals sensitive report tabs and valuation only with pricing permission', function (): void {
    $viewer = reportViewer([
        InventoryPermission::CatalogView,
        InventoryPermission::StockView,
        InventoryPermission::PricingView,
    ]);
    $stock = InventoryStock::factory()->create([
        'available_quantity' => 4,
        'damaged_quantity' => 3,
        'product_variant_id' => ProductVariant::factory()->create(['cost_price' => 5]),
    ]);

    $component = Livewire::actingAs($viewer)
        ->test(ManageInventoryReports::class)
        ->set('activeTab', InventoryReportType::StockLevels->value)
        ->assertCanSeeTableRecords([$stock])
        ->assertTableColumnVisible('usable_value')
        ->assertTableColumnStateSet('usable_value', 20.0, $stock);

    expect(array_keys($component->instance()->getTabs()))->toContain(
        InventoryReportType::SupplierComparison->value,
        InventoryReportType::PriceHistory->value,
        InventoryReportType::PricingTiers->value,
        InventoryReportType::CustomerAssignments->value,
        InventoryReportType::FloorOverrides->value,
    );
});

it('renders every report tab with a model-native read-only table', function (): void {
    $viewer = reportViewer([
        InventoryPermission::CatalogView,
        InventoryPermission::StockView,
        InventoryPermission::MovementView,
        InventoryPermission::PricingView,
        InventoryPermission::ImportManage,
    ]);
    $component = Livewire::actingAs($viewer)->test(ManageInventoryReports::class);

    foreach (InventoryReportType::cases() as $type) {
        $component->set('activeTab', $type->value)->assertOk();

        expect($component->instance()->getTable()->getColumns())->not->toBeEmpty()
            ->and($component->instance()->getTable()->getActions())->toBeEmpty()
            ->and($component->instance()->getTable()->getBulkActions())->toBeEmpty();
    }
});

it('exposes no report mutation controls and denies incomplete permission combinations', function (): void {
    $viewer = reportViewer([InventoryPermission::CatalogView]);
    $component = Livewire::actingAs($viewer)->test(ManageInventoryReports::class)->assertOk();

    expect(InventoryReportResource::canCreate())->toBeFalse()
        ->and(InventoryReportResource::canDeleteAny())->toBeFalse()
        ->and(InventoryReportResource::canForceDeleteAny())->toBeFalse()
        ->and($component->instance()->getTable()->getActions())->toBeEmpty()
        ->and($component->instance()->getTable()->getBulkActions())->toBeEmpty();

    $reportOnly = User::factory()->admin()->create();
    $reportOnly->givePermissionTo(InventoryPermission::ReportView->value);

    $this->actingAs($reportOnly)
        ->get(InventoryReportResource::getUrl())
        ->assertForbidden();
});

/** @param list<InventoryPermission> $sourcePermissions */
function reportViewer(array $sourcePermissions): User
{
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo([
        InventoryPermission::ReportView->value,
        ...array_map(fn (InventoryPermission $permission): string => $permission->value, $sourcePermissions),
    ]);

    return $viewer;
}
