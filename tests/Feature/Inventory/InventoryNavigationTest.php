<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\BarcodeWorkbench;
use App\Filament\Pages\InventoryDashboard;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\InventoryAlerts\InventoryAlertResource;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\InventoryCorrections\InventoryCorrectionResource;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use App\Filament\Resources\InventoryImportRuns\InventoryImportRunResource;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryReports\InventoryReportResource;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Filament\Resources\OutboundFulfillments\OutboundFulfillmentResource;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\WarehouseReplenishmentPolicies\WarehouseReplenishmentPolicyResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Filament\Support\WorkspaceNavigation;
use App\Models\InventoryOperation;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * A user holding exactly the given `inventory.*` permissions (all of them by default), so each
 * navigation item resolves or hides by policy — see App\Policies\Concerns\ChecksInventoryPermissions.
 *
 * @param  list<InventoryPermission>|null  $permissions
 */
function actingAsInventoryUser(?array $permissions = null): User
{
    (new InventoryPermissionSeeder)->run();

    $role = Role::firstOrCreate(['name' => 'inventory-role-'.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions($permissions === null
        ? InventoryPermission::values()
        : array_map(static fn (InventoryPermission $permission): string => $permission->value, $permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** @return list<string> */
function renderedInventorySidebarLabels(): array
{
    return collect(Filament::getPanel('admin')->buildNavigation())
        ->flatMap(fn (NavigationGroup $group): array => $group->getItems())
        ->map(fn (NavigationItem $item): string => $item->getLabel())
        ->values()
        ->all();
}

it('renders the inventory sidebar as ten workspace destinations grouped into sections', function (): void {
    $user = actingAsInventoryUser();

    $this->actingAs($user)->get(WarehouseResource::getUrl())->assertOk();

    expect(AdminModuleRegistry::activeGroupKey())->toBe('inventory');

    $navigation = collect(Filament::getPanel('admin')->buildNavigation());

    expect($navigation->map(fn (NavigationGroup $group): ?string => $group->getLabel())->values()->all())->toBe([
        __('admin.sections.overview'),
        __('admin.sections.stock'),
        __('admin.sections.operations'),
        __('admin.sections.planning'),
        __('admin.sections.reports'),
        __('admin.sections.setup'),
    ])
        ->and(renderedInventorySidebarLabels())->toBe([
            __('admin.dashboard'),
            __('admin.sections.stock'),
            __('admin.sections.inbound'),
            __('admin.sections.outbound'),
            __('admin.sections.operations'),
            __('admin.sections.planning_alerts'),
            __('admin.resources.inventory_reports'),
            __('admin.resources.warehouses'),
            __('admin.resources.catalog_setup'),
            __('admin.sections.inventory_setup'),
        ]);
});

it('no longer lists the fragmented inventory resources as sidebar entries', function (): void {
    $user = actingAsInventoryUser();

    $this->actingAs($user)->get(WarehouseResource::getUrl())->assertOk();

    expect(renderedInventorySidebarLabels())
        ->not->toContain(__('admin.resources.stock_levels'))
        ->not->toContain(__('admin.resources.inventory_lots'))
        ->not->toContain(__('admin.resources.serialized_inventory_units'))
        ->not->toContain(__('admin.resources.stock_movements'))
        ->not->toContain(__('admin.resources.reservations'))
        ->not->toContain(__('admin.resources.adjustments'))
        ->not->toContain(__('admin.resources.returns'))
        ->not->toContain(__('admin.resources.barcode_workbench'))
        ->not->toContain(__('admin.resources.catalog_imports'))
        ->not->toContain(__('admin.resources.package_types'));
});

it('declares no duplicate sidebar destination for any inventory class', function (): void {
    $inventory = collect(AdminModuleRegistry::groups())->firstWhere('key', 'inventory');

    $links = collect($inventory['items'])->pluck('link');

    expect($inventory['items'])->toHaveCount(10)
        ->and($links->all())->toBe($links->unique()->all());
});

it('highlights the owning workspace while one of its records is open', function (): void {
    $user = actingAsInventoryUser();

    $this->actingAs($user)->get(StockMovementResource::getUrl())->assertOk();

    $stock = collect(AdminModuleRegistry::groups())
        ->firstWhere('key', 'inventory')['items'][1];

    expect(AdminModuleRegistry::activeGroupKey())->toBe('inventory')
        ->and(WorkspaceNavigation::isActive($stock))->toBeTrue();
});

it('keeps every former inventory resource routable and owned by the inventory module', function (string $resource): void {
    $inventory = collect(AdminModuleRegistry::groups())->firstWhere('key', 'inventory');

    $user = actingAsInventoryUser();

    $this->actingAs($user)->get($resource::getUrl())->assertOk();

    expect(AdminModuleRegistry::activeGroupKey())->toBe('inventory')
        ->and(AdminModuleRegistry::memberClassesOf($inventory))->toContain($resource);
})->with([
    StockLevelResource::class,
    ProductResource::class,
    InventoryLotResource::class,
    SerializedInventoryUnitResource::class,
    StockMovementResource::class,
    PurchaseInboundResource::class,
    OutboundFulfillmentResource::class,
    ShipmentResource::class,
    PackageResource::class,
    AdjustmentResource::class,
    InventoryCountResource::class,
    ReturnResource::class,
    InventoryCorrectionResource::class,
    InventoryConditionChangeResource::class,
    InventoryReservationResource::class,
    InventoryAlertResource::class,
    WarehouseReplenishmentPolicyResource::class,
    WarehouseResource::class,
    InventoryImportRunResource::class,
]);

it('keeps the operation list routes and the barcode workbench directly reachable', function (): void {
    $user = actingAsInventoryUser();

    foreach (['receipts', 'deliveries', 'transfers'] as $page) {
        $this->actingAs($user)->get(InventoryOperationResource::getUrl($page))->assertOk();

        expect(AdminModuleRegistry::activeGroupKey())->toBe('inventory');
    }

    $this->actingAs($user)->get(BarcodeWorkbench::getUrl())->assertOk();

    expect(AdminModuleRegistry::activeGroupKey())->toBe('inventory')
        ->and(BarcodeWorkbench::getUrl())->toEndWith('/admin/inventory/barcode');
});

it('keeps inventory reports inside the inventory module without breaking the url', function (): void {
    $user = actingAsInventoryUser();

    $inventory = collect(AdminModuleRegistry::groups())->firstWhere('key', 'inventory');
    $reports = collect(AdminModuleRegistry::groups())->firstWhere('key', 'reports');

    expect(collect($inventory['items'])->pluck('link'))->toContain(InventoryReportResource::class)
        ->and(collect($reports['items'])->pluck('link'))->not->toContain(InventoryReportResource::class);

    $this->actingAs($user)->get(InventoryReportResource::getUrl())->assertOk();

    expect(AdminModuleRegistry::activeGroupKey())->toBe('inventory');
});

it('keeps inventory configuration inside the inventory module', function (): void {
    $inventory = collect(AdminModuleRegistry::groups())->firstWhere('key', 'inventory');
    $system = collect(AdminModuleRegistry::groups())->firstWhere('key', 'system');
    $setup = collect($inventory['items'])->firstWhere('label', 'admin.sections.inventory_setup');

    expect(collect($inventory['items'])->pluck('label'))
        ->toContain('admin.resources.catalog_setup')
        ->and(collect($setup['tabs'])->pluck('label'))
        ->toContain('admin.resources.package_types')
        ->toContain('admin.resources.inventory_settings')
        ->and(collect($system['items'])->pluck('label'))
        ->not->toContain('admin.resources.catalog_setup')
        ->not->toContain('admin.resources.package_types')
        ->not->toContain('admin.resources.inventory_settings');
});

it('renders the workspace tab bar on a tab page with every permitted tab', function (): void {
    $user = actingAsInventoryUser();

    $response = $this->actingAs($user)->get(StockLevelResource::getUrl())->assertOk();

    foreach (['stock_levels', 'products', 'inventory_lots', 'serialized_inventory_units', 'stock_movements'] as $tab) {
        $response->assertSee(__('admin.resources.'.$tab), escape: false);
    }

    $response->assertSee(StockMovementResource::getUrl(), escape: false)
        ->assertSee(__('admin.resources.catalog_imports'), escape: false);
});

it('offers the barcode workbench as a tool on the inbound, outbound and operations workspaces', function (): void {
    $user = actingAsInventoryUser();

    foreach ([PurchaseInboundResource::getUrl(), InventoryOperationResource::getUrl('deliveries'), AdjustmentResource::getUrl()] as $url) {
        $this->actingAs($user)->get($url)->assertOk()
            ->assertSee(BarcodeWorkbench::getUrl(), escape: false);
    }
});

it('shows only the tabs a restricted user may open, and never a tab that would 403', function (): void {
    $user = actingAsInventoryUser([
        InventoryPermission::TransferView,
        InventoryPermission::AdjustmentView,
    ]);

    $response = $this->actingAs($user)->get(InventoryOperationResource::getUrl('transfers'))->assertOk();

    $response->assertSee(__('admin.resources.adjustments'), escape: false)
        ->assertDontSee(__('admin.resources.reservations'), escape: false)
        ->assertDontSee(__('admin.resources.returns'), escape: false)
        ->assertDontSee(BarcodeWorkbench::getUrl(), escape: false);

    $this->actingAs($user)->get(InventoryReservationResource::getUrl())->assertForbidden();
});

it('lets a stock-only user reach the stock workspace but not the operations workspace', function (): void {
    $user = actingAsInventoryUser([InventoryPermission::StockView, InventoryPermission::MovementView]);

    $this->actingAs($user)->get(StockLevelResource::getUrl())->assertOk();

    $inventory = collect(AdminModuleRegistry::groups())->firstWhere('key', 'inventory');
    $items = collect($inventory['items']);

    $stock = $items->firstWhere('label', 'admin.sections.stock');
    $operations = $items->firstWhere('label', 'admin.sections.operations');

    expect(AdminModuleRegistry::resolveItemUrl($stock))->toBe(StockLevelResource::getUrl())
        ->and(AdminModuleRegistry::resolveItemUrl($operations))->toBeNull()
        ->and(AdminModuleRegistry::isItemAccessDenied($operations))->toBeTrue()
        ->and(renderedInventorySidebarLabels())->not->toContain(__('admin.sections.operations'));
});

it('lands a user with a single operations permission on the first workspace tab they can open', function (): void {
    $user = actingAsInventoryUser([InventoryPermission::CountView]);

    $this->actingAs($user);

    $inventory = collect(AdminModuleRegistry::groups())->firstWhere('key', 'inventory');
    $operations = collect($inventory['items'])->firstWhere('label', 'admin.sections.operations');

    expect(AdminModuleRegistry::resolveItemUrl($operations))->toBe(InventoryCountResource::getUrl());
});

it('lets a transfer user into transfers and keeps the workspace entry visible', function (): void {
    $user = actingAsInventoryUser([InventoryPermission::TransferView, InventoryPermission::TransferCreate]);

    $this->actingAs($user)->get(InventoryOperationResource::getUrl('transfers'))->assertOk();

    $inventory = collect(AdminModuleRegistry::groups())->firstWhere('key', 'inventory');
    $operations = collect($inventory['items'])->firstWhere('label', 'admin.sections.operations');

    expect(AdminModuleRegistry::resolveItemUrl($operations))->toBe(InventoryOperationResource::getUrl('transfers'))
        ->and(renderedInventorySidebarLabels())->toContain(__('admin.sections.operations'));
});

it('resolves the operation workspace tab from the record being viewed', function (): void {
    $user = actingAsInventoryUser();

    $transfer = InventoryOperation::factory()->internalTransfer()->create();
    $delivery = InventoryOperation::factory()->delivery()->create();

    $inventory = collect(AdminModuleRegistry::groups())->firstWhere('key', 'inventory');
    $items = collect($inventory['items']);

    $this->actingAs($user)->get(InventoryOperationResource::getUrl('view', ['record' => $transfer]))->assertOk();

    expect(WorkspaceNavigation::isActive($items->firstWhere('label', 'admin.sections.operations')))->toBeTrue()
        ->and(WorkspaceNavigation::isActive($items->firstWhere('label', 'admin.sections.outbound')))->toBeFalse();

    $this->actingAs($user)->get(InventoryOperationResource::getUrl('view', ['record' => $delivery]))->assertOk();

    expect(WorkspaceNavigation::isActive($items->firstWhere('label', 'admin.sections.outbound')))->toBeTrue()
        ->and(WorkspaceNavigation::isActive($items->firstWhere('label', 'admin.sections.operations')))->toBeFalse();
});

it('keeps the inventory dashboard as the overview entry', function (): void {
    $user = actingAsInventoryUser();

    $this->actingAs($user)->get(InventoryDashboard::getUrl())->assertOk();

    expect(AdminModuleRegistry::activeGroupKey())->toBe('inventory');
});

it('renders a module sidebar as named sections that all come from its declared sections', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(CustomerResource::getUrl());

    expect(AdminModuleRegistry::activeGroupKey())->toBe('crm');

    $declared = collect(collect(AdminModuleRegistry::groups())->firstWhere('key', 'crm')['sections'])
        ->map(fn (array $section): string => __($section['label']))
        ->all();

    $renderedLabels = collect(Filament::getPanel('admin')->buildNavigation())
        ->map(fn (NavigationGroup $group): ?string => $group->getLabel())
        ->filter()
        ->values();

    expect($renderedLabels)->not->toBeEmpty()
        ->and($renderedLabels->diff($declared)->all())->toBe([]);
});

it('offers high-value quick actions on the inventory overview, gated by permission', function (): void {
    $full = actingAsInventoryUser();

    $this->actingAs($full)->get(InventoryDashboard::getUrl())->assertOk()
        ->assertSee(BarcodeWorkbench::getUrl(), escape: false)
        ->assertSee(__('admin.inventory.operation.actions.create_internal_transfer'), escape: false);

    $stockOnly = actingAsInventoryUser([InventoryPermission::StockView]);

    $this->actingAs($stockOnly)->get(InventoryDashboard::getUrl())->assertOk()
        ->assertDontSee(BarcodeWorkbench::getUrl(), escape: false)
        ->assertDontSee(__('admin.inventory.operation.actions.create_internal_transfer'), escape: false);
});

it('links the planning workspace to the low stock view of stock levels', function (): void {
    $user = actingAsInventoryUser();

    $this->actingAs($user)->get(InventoryAlertResource::getUrl())->assertOk()
        ->assertSee(urlencode('tableFilters[low_stock][isActive]'), escape: false)
        ->assertSee(__('admin.inventory.stock.low_stock'), escape: false);
});

it('links a warehouse to its stock, movements and reservations', function (): void {
    $user = actingAsInventoryUser();
    $warehouse = Warehouse::factory()->create();

    $this->actingAs($user)->get(WarehouseResource::getUrl('view', ['record' => $warehouse]))->assertOk()
        ->assertSee(__('admin.inventory.warehouse.actions.view_stock'), escape: false)
        ->assertSee(__('admin.inventory.warehouse.actions.view_movements'), escape: false)
        ->assertSee(__('admin.inventory.warehouse.actions.view_reservations'), escape: false);
});
