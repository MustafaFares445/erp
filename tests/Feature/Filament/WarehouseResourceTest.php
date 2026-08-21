<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\ModulePlaceholder;
use App\Filament\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Resources\Warehouses\Pages\EditWarehouse;
use App\Filament\Resources\Warehouses\Pages\ListWarehouses;
use App\Filament\Resources\Warehouses\Pages\ViewWarehouse;
use App\Filament\Resources\Warehouses\RelationManagers\StockLevelsRelationManager;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function createWarehouseManager(): User
{
    $role = Role::firstOrCreate(['name' => 'warehouse-manager', 'guard_name' => 'web']);
    $role->givePermissionTo([
        InventoryPermission::WarehouseView->value,
        InventoryPermission::WarehouseManage->value,
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('creates a warehouse with a unique code and lists it', function (): void {
    $admin = createWarehouseManager();

    Livewire::actingAs($admin)
        ->test(CreateWarehouse::class)
        ->fillForm([
            'name' => 'Central Warehouse',
            'code' => 'WH-001',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $warehouse = Warehouse::query()->where('code', 'WH-001')->firstOrFail();

    expect($warehouse->name)->toBe('Central Warehouse');

    Livewire::actingAs($admin)
        ->test(ListWarehouses::class)
        ->assertCanSeeTableRecords([$warehouse]);
});

it('rejects a duplicate warehouse code and creates no record', function (): void {
    $admin = createWarehouseManager();
    Warehouse::factory()->create(['code' => 'WH-DUP']);

    Livewire::actingAs($admin)
        ->test(CreateWarehouse::class)
        ->fillForm([
            'name' => 'Another Warehouse',
            'code' => 'WH-DUP',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);

    expect(Warehouse::query()->where('code', 'WH-DUP')->count())->toBe(1);
});

it('exposes only warehouse stock as a relation manager', function (): void {
    expect(WarehouseResource::getRelations())->toBe([StockLevelsRelationManager::class]);
});

it('deactivates a warehouse', function (): void {
    $admin = createWarehouseManager();
    $warehouse = Warehouse::factory()->create(['is_active' => true]);

    Livewire::actingAs($admin)
        ->test(EditWarehouse::class, ['record' => $warehouse->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($warehouse->refresh()->is_active)->toBeFalse();
});

it('blocks deleting a warehouse referenced by stock rows and allows deactivation instead', function (): void {
    $admin = createWarehouseManager();
    $warehouse = Warehouse::factory()->create();
    InventoryStock::factory()->create(['warehouse_id' => $warehouse->id]);

    expect($admin->can('delete', $warehouse))->toBeFalse();

    Livewire::actingAs($admin)
        ->test(EditWarehouse::class, ['record' => $warehouse->getKey()])
        ->assertActionHidden(DeleteAction::class);

    expect($warehouse->fresh()->trashed())->toBeFalse();
});

it('blocks deleting a warehouse referenced by movement rows', function (): void {
    $admin = createWarehouseManager();
    $warehouse = Warehouse::factory()->create();
    InventoryMovement::factory()->create(['warehouse_id' => $warehouse->id]);

    expect($admin->can('delete', $warehouse))->toBeFalse();
});

it('allows deleting an unreferenced warehouse as a reversible soft delete', function (): void {
    $admin = createWarehouseManager();
    $warehouse = Warehouse::factory()->create();

    expect($admin->can('delete', $warehouse))->toBeTrue();

    Livewire::actingAs($admin)
        ->test(EditWarehouse::class, ['record' => $warehouse->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified();

    expect($warehouse->fresh()->trashed())->toBeTrue();
    expect(Warehouse::withTrashed()->find($warehouse->id))->not->toBeNull();
});

it('populates created_by and updated_by from the acting administrator', function (): void {
    $admin = createWarehouseManager();

    Livewire::actingAs($admin)
        ->test(CreateWarehouse::class)
        ->fillForm([
            'name' => 'Blame Warehouse',
            'code' => 'WH-BLAME',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $warehouse = Warehouse::query()->where('code', 'WH-BLAME')->firstOrFail();

    expect($warehouse->created_by)->toBe($admin->id)
        ->and($warehouse->updated_by)->toBe($admin->id);
});

it('shows a read-only stock levels relation manager with no write actions', function (): void {
    $admin = createWarehouseManager();

    $role = Role::query()->where('name', 'warehouse-manager')->firstOrFail();
    $role->givePermissionTo(InventoryPermission::StockView->value);

    $warehouse = Warehouse::factory()->create();
    $stock = InventoryStock::factory()->lowStock()->create(['warehouse_id' => $warehouse->id]);
    $stockWithoutReorderLevel = InventoryStock::factory()->withoutReorderLevel()->create(['warehouse_id' => $warehouse->id]);

    $component = Livewire::actingAs($admin)
        ->test(StockLevelsRelationManager::class, [
            'ownerRecord' => $warehouse,
            'pageClass' => EditWarehouse::class,
        ])
        ->assertOk()
        ->assertCanSeeTableRecords([$stock, $stockWithoutReorderLevel]);

    expect($component->instance()->getTable()->getActions())->toBeEmpty()
        ->and($component->instance()->getTable()->getHeaderActions())->toBeEmpty();
});

it('denies inventory dashboard warehouse access without the warehouse view permission', function (): void {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get(WarehouseResource::getUrl('index'))->assertForbidden();
});

it('grants warehouse list access with the warehouse view permission', function (): void {
    $admin = createWarehouseManager();

    $this->actingAs($admin)->get(WarehouseResource::getUrl('index'))->assertOk();
});

it('shows warehouse details and exposes the inventory model relationships', function (): void {
    $admin = createWarehouseManager();
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create();
    $movement = InventoryMovement::factory()->for($variant)->for($warehouse)->create();

    Livewire::actingAs($admin)
        ->test(ViewWarehouse::class, ['record' => $warehouse->getKey()])
        ->assertOk()
        ->assertSee($warehouse->code);

    expect($product->variants)->toHaveCount(1)
        ->and($variant->product->is($product))->toBeTrue()
        ->and($variant->stocks->first()->is($stock))->toBeTrue()
        ->and($variant->movements->first()->is($movement))->toBeTrue();
});

it('allows warehouse update and restore but never force delete', function (): void {
    $admin = createWarehouseManager();
    $warehouse = Warehouse::factory()->create();

    expect($admin->can('update', $warehouse))->toBeTrue()
        ->and($admin->can('restore', $warehouse))->toBeTrue()
        ->and($admin->can('forceDelete', $warehouse))->toBeFalse();
});

it('denies every warehouse bulk mutation', function (): void {
    $admin = createWarehouseManager();
    Warehouse::factory()->create();

    $this->actingAs($admin);

    expect(WarehouseResource::canDeleteAny())->toBeFalse()
        ->and(WarehouseResource::canForceDeleteAny())->toBeFalse()
        ->and(WarehouseResource::canRestoreAny())->toBeFalse();

    $component = Livewire::actingAs($admin)
        ->test(ListWarehouses::class);

    expect($component->instance()->getTable()->getBulkActions())->toBeEmpty();
});

it('hides denied inventory resources from custom navigation and placeholder urls', function (): void {
    $admin = User::factory()->create();

    $this->actingAs($admin);

    $navigationLabels = collect(AdminModuleRegistry::navigationItems(onlyGroupKey: 'inventory'))
        ->map(static fn (NavigationItem $navigationItem): string => $navigationItem->getLabel())
        ->all();

    expect($navigationLabels)
        ->not->toContain('Warehouses')
        ->not->toContain('Stock Levels')
        ->not->toContain('Stock Movements');

    $this->get(ModulePlaceholder::getUrl([
        'group' => 'inventory',
        'item' => 'warehouses',
    ]))->assertForbidden();
});

it('denies warehouse deletion before evaluating reference checks for an unauthorized administrator', function (): void {
    $warehouse = Warehouse::factory()->create();
    $admin = User::factory()->create();

    expect($admin->can('delete', $warehouse))->toBeFalse();
});

it('paginates every warehouse exactly once across pages with a deterministic default sort', function (): void {
    $admin = createWarehouseManager();
    $warehouses = Warehouse::factory()->count(29)->create();

    $component = Livewire::actingAs($admin)->test(ListWarehouses::class);

    expect($component->instance()->getTable()->getDefaultSortColumn())->toBe('code');

    $seenIds = [];

    foreach ([1, 2, 3] as $page) {
        $component->call('gotoPage', $page);

        $pageIds = $component->instance()->getTableRecords()->pluck('id')->all();

        expect($pageIds)->not->toBeEmpty()
            ->and(array_intersect($pageIds, $seenIds))->toBeEmpty();

        $seenIds = [...$seenIds, ...$pageIds];
    }

    expect($component->instance()->getAllTableRecordsCount())->toBe(29)
        ->and($seenIds)->toHaveCount(29)
        ->and(collect($seenIds)->sort()->values()->all())->toBe($warehouses->pluck('id')->sort()->values()->all());
});

it('keeps pagination gap-free and duplicate-free after sorting and after a mid-list deletion', function (): void {
    $admin = createWarehouseManager();
    $warehouses = Warehouse::factory()->count(14)->create();

    $component = Livewire::actingAs($admin)->test(ListWarehouses::class);

    $component->call('sortTable', 'code', 'desc')->call('gotoPage', 1);
    $firstPageDesc = $component->instance()->getTableRecords()->pluck('id')->all();
    $component->call('gotoPage', 2);
    $secondPageDesc = $component->instance()->getTableRecords()->pluck('id')->all();

    expect(array_intersect($firstPageDesc, $secondPageDesc))->toBeEmpty()
        ->and([...$firstPageDesc, ...$secondPageDesc])->toHaveCount(14);

    $deleted = $warehouses->first();
    $deleted->delete();

    $component->call('gotoPage', 1);
    $remainingIds = $component->instance()->getTableRecords()->pluck('id')->all();

    expect($component->instance()->getAllTableRecordsCount())->toBe(13)
        ->and($remainingIds)->not->toContain($deleted->getKey());
});
