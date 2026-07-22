<?php

declare(strict_types=1);

use App\Data\Inventory\WarehouseData;
use App\Enums\InventoryPermission;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\ViewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function createStockViewer(): User
{
    $role = Role::firstOrCreate(['name' => 'stock-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::StockView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('shows each stock balance with its variant and warehouse', function (): void {
    $admin = createStockViewer();
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '2.000',
        'available_quantity' => '8.000',
        'reorder_level' => '5.000',
    ]);

    Livewire::actingAs($admin)
        ->test(ListStockLevels::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$stock]);
});

it('exposes no stock write actions', function (): void {
    $admin = createStockViewer();
    $stock = InventoryStock::factory()->create();

    expect(StockLevelResource::canCreate())->toBeFalse()
        ->and(StockLevelResource::canForceDelete($stock))->toBeFalse()
        ->and(StockLevelResource::canDeleteAny())->toBeFalse()
        ->and(StockLevelResource::canForceDeleteAny())->toBeFalse()
        ->and(StockLevelResource::canRestore($stock))->toBeFalse()
        ->and(StockLevelResource::canRestoreAny())->toBeFalse()
        ->and(StockLevelResource::canReplicate($stock))->toBeFalse()
        ->and(StockLevelResource::canReorder())->toBeFalse();

    $component = Livewire::actingAs($admin)
        ->test(ListStockLevels::class)
        ->assertCanSeeTableRecords([$stock]);

    expect($component->instance()->getTable()->getActions())->toContainOnlyInstancesOf(ViewAction::class)
        ->and($component->instance()->getTable()->getHeaderActions())->toBeEmpty()
        ->and($component->instance()->getTable()->getBulkActions())->toBeEmpty();
});

it('filters low stock inclusively and excludes stocks without a reorder level', function (): void {
    $admin = createStockViewer();
    $atReorderLevel = InventoryStock::factory()->create([
        'available_quantity' => '5.000',
        'reorder_level' => '5.000',
    ]);
    $belowReorderLevel = InventoryStock::factory()->create([
        'available_quantity' => '4.000',
        'reorder_level' => '5.000',
    ]);
    $aboveReorderLevel = InventoryStock::factory()->create([
        'available_quantity' => '6.000',
        'reorder_level' => '5.000',
    ]);
    $withoutReorderLevel = InventoryStock::factory()->create([
        'reorder_level' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(ListStockLevels::class)
        ->filterTable('low_stock')
        ->assertCanSeeTableRecords([$atReorderLevel, $belowReorderLevel])
        ->assertCanNotSeeTableRecords([$aboveReorderLevel, $withoutReorderLevel]);
});

it('filters stock by warehouse and searches by variant SKU', function (): void {
    $admin = createStockViewer();
    $warehouse = Warehouse::factory()->create();
    $matchingVariant = ProductVariant::factory()->create(['sku' => 'SKU-MATCH']);
    $matchingStock = InventoryStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $matchingVariant->id,
    ]);
    $otherStock = InventoryStock::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListStockLevels::class)
        ->filterTable('warehouse_id', $warehouse->id)
        ->searchTable('SKU-MATCH')
        ->assertCanSeeTableRecords([$matchingStock])
        ->assertCanNotSeeTableRecords([$otherStock]);
});

it('shows the same variant as separate rows for separate warehouses', function (): void {
    $admin = createStockViewer();
    $variant = ProductVariant::factory()->create();
    $stocks = InventoryStock::factory()->count(2)->for($variant)->create();

    Livewire::actingAs($admin)
        ->test(ListStockLevels::class)
        ->assertCanSeeTableRecords($stocks);
});

it('denies the stock resource without the stock view permission', function (): void {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get(StockLevelResource::getUrl('index'))->assertForbidden();
});

it('exposes the translated stock navigation label and shared warehouse data shape', function (): void {
    $data = new WarehouseData('Warehouse', 'WH-DATA', null, true);

    expect(StockLevelResource::getNavigationLabel())->toBe('Stock Levels')
        ->and($data->code)->toBe('WH-DATA');
});
