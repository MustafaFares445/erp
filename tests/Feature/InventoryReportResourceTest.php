<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Enums\MovementType;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryReports\InventoryReportResource;
use App\Filament\Resources\InventoryReports\Pages\ManageInventoryReports;
use App\Filament\Resources\InventoryReports\Tables\InventoryReportsTable;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Schemas\Schema;
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

it('does not register the inventory reports page in navigation', function (): void {
    expect(InventoryReportResource::shouldRegisterNavigation())->toBeFalse();
});

it('makes the shared pricing reports available to CRM report viewers', function (): void {
    (new CrmPermissionSeeder)->run();
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $this->actingAs($reviewer)
        ->get(InventoryReportResource::getUrl())
        ->assertOk();

    $tabs = Livewire::actingAs($reviewer)
        ->test(ManageInventoryReports::class)
        ->instance()
        ->getTabs();

    expect(array_keys($tabs))->toBe([
        InventoryReportType::SupplierComparison->value,
        InventoryReportType::PriceHistory->value,
        InventoryReportType::PricingTiers->value,
        InventoryReportType::CustomerAssignments->value,
        InventoryReportType::FloorOverrides->value,
    ]);
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

it('renders and filters enriched movement report context', function (): void {
    $viewer = reportViewer([InventoryPermission::MovementView]);
    $matching = InventoryMovement::factory()->create([
        'movement_type' => MovementType::Receipt,
        'stock_condition_from' => StockCondition::Saleable,
        'stock_condition_to' => StockCondition::Saleable,
        'source_type' => 'inventory_operation',
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => ProductVariant::factory()->create()->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity_delta' => '2.000000',
        'quantity' => '2.000000',
    ]);
    $other = InventoryMovement::factory()->create([
        'movement_type' => MovementType::Adjustment,
        'stock_condition_from' => StockCondition::Saleable,
        'stock_condition_to' => StockCondition::Saleable,
        'source_type' => 'adjustment',
    ]);

    Livewire::actingAs($viewer)
        ->test(ManageInventoryReports::class)
        ->set('activeTab', InventoryReportType::Movements->value)
        ->filterTable('movement_type', MovementType::Receipt->value)
        ->filterTable('stock_condition_from', StockCondition::Saleable->value)
        ->filterTable('source_type', 'inventory_operation')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other])
        ->assertTableColumnVisible('transaction_quantity')
        ->assertTableColumnVisible('transactionUnit.symbol')
        ->assertTableColumnVisible('base_quantity_delta')
        ->assertTableColumnVisible('stock_condition_from')
        ->assertTableColumnVisible('stock_condition_to')
        ->assertTableColumnVisible('source_line_type')
        ->assertTableColumnVisible('reversal_of_movement_id');
});

it('renders canonical receipt provenance on the devices report without legacy receipt relations', function (): void {
    $viewer = reportViewer([InventoryPermission::StockView]);
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $device = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'inventory_receipt_item_id' => null,
    ]);
    $withoutReceiptMovement = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'inventory_receipt_item_id' => null,
    ]);
    InventoryMovement::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'movement_type' => MovementType::Receipt,
            'quantity' => '1.000000',
            'serialized_inventory_unit_id' => $device->getKey(),
            'source_type' => 'inventory_operation',
            'source_id' => 321,
        ]);

    Livewire::actingAs($viewer)
        ->test(ManageInventoryReports::class)
        ->set('activeTab', InventoryReportType::Devices->value)
        ->assertCanSeeTableRecords([$device, $withoutReceiptMovement])
        ->assertTableColumnStateSet('receipt_source', 'inventory_operation #321', $device)
        ->assertTableColumnStateSet('receipt_source', null, $withoutReceiptMovement);
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

it('covers report resource metadata fallbacks and defensive formatters', function (): void {
    $viewer = reportViewer([InventoryPermission::CatalogView]);
    $this->actingAs($viewer);
    $component = Livewire::actingAs($viewer)->test(ManageInventoryReports::class);
    $page = $component->instance();
    $reportType = new ReflectionMethod($page, 'reportType');
    $reportFilters = new ReflectionMethod($page, 'reportFilters');
    $integerKey = new ReflectionMethod(InventoryReportsTable::class, 'integerKey');
    $json = new ReflectionMethod(InventoryReportsTable::class, 'json');

    $page->tableFilters = [
        'ignored' => 'not-an-array',
        'direct' => ['value' => 'active'],
        'grouped' => ['warehouse_id' => 10, 0 => 'discarded'],
    ];

    expect(InventoryReportResource::getNavigationLabel())->toBe(__('admin.resources.inventory_reports'))
        ->and(InventoryReportResource::form(Schema::make())->getComponents())->toBe([])
        ->and(InventoryReportResource::canViewAny())->toBeTrue()
        ->and($page->isReport(InventoryReportType::Catalog))->toBeTrue()
        ->and($reportFilters->invoke($page))->toBe([
            'direct' => 'active',
            'warehouse_id' => 10,
        ])
        ->and(fn (): mixed => $integerKey->invoke(null, new SerializedInventoryUnit))->toThrow(LogicException::class)
        ->and($integerKey->invoke(null, SerializedInventoryUnit::factory()->create()))->toBeInt()
        ->and($json->invoke(null, null))->toBe('')
        ->and($json->invoke(null, []))->toBe('')
        ->and($json->invoke(null, ['ok' => true]))->toBe('{"ok":true}')
        ->and($json->invoke(null, ["\xB1\x31"]))->toBe('');

    $page->activeTab = null;
    expect($reportType->invoke($page))->toBe(InventoryReportType::Catalog);

    auth()->logout();

    expect($reportType->invoke($page))->toBe(InventoryReportType::Catalog);
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
