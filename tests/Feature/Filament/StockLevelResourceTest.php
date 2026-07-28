<?php

declare(strict_types=1);

use App\Data\Inventory\WarehouseData;
use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Filament\Resources\StockLevels\Actions\StockDamageActions;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Illuminate\Auth\Access\AuthorizationException;
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

function createStockManager(): User
{
    $user = createStockViewer();
    $user->givePermissionTo(InventoryPermission::AdjustmentConfirm->value);

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

it('shows stock quantity statistics above the stock levels table', function (): void {
    $admin = createStockViewer();
    InventoryStock::factory()->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '2.000',
        'damaged_quantity' => '1.000',
        'available_quantity' => '7.000',
    ]);

    Livewire::actingAs($admin)
        ->test(ListStockLevels::class)
        ->assertSee(__('admin.inventory.stock.on_hand_quantity'))
        ->assertSee(__('admin.inventory.stock.reserved_quantity'))
        ->assertSee(__('admin.inventory.stock.damaged_quantity'))
        ->assertSee(__('admin.inventory.stock.available_quantity'))
        ->assertSee(__('admin.inventory.stock.in_transit_quantity'))
        ->assertSee('10.000')
        ->assertSee('2.000')
        ->assertSee('1.000')
        ->assertSee('7.000');
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

    $actions = $component->instance()->getTable()->getActions();

    expect($actions)->toHaveCount(5)
        ->and($actions[0])->toBeInstanceOf(ViewAction::class)
        ->and($component->instance()->getTable()->getHeaderActions())->toBeEmpty()
        ->and($component->instance()->getTable()->getBulkActions())->toBeEmpty();

    $component
        ->assertActionHidden(TestAction::make('damage')->table($stock))
        ->assertActionHidden(TestAction::make('recover_damage')->table($stock))
        ->assertActionHidden(TestAction::make('dispose_damage')->table($stock));
});

it('allows an adjustment confirmer to damage recover and dispose stock from the table', function (): void {
    $manager = createStockManager();
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 5,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 5,
    ]);

    Livewire::actingAs($manager)
        ->test(ListStockLevels::class)
        ->assertActionVisible(TestAction::make('damage')->table($stock))
        ->callAction(TestAction::make('damage')->table($stock), data: [
            'quantity' => 2,
            'reason' => 'Damaged in handling',
        ])
        ->assertHasNoActionErrors();

    expect((float) $stock->fresh()->damaged_quantity)->toBe(2.0)
        ->and((float) $stock->fresh()->available_quantity)->toBe(3.0);

    Livewire::actingAs($manager)
        ->test(ListStockLevels::class)
        ->assertActionVisible(TestAction::make('recover_damage')->table($stock))
        ->callAction(TestAction::make('recover_damage')->table($stock), data: [
            'quantity' => 1,
            'reason' => 'Repaired',
        ])
        ->assertHasNoActionErrors();

    Livewire::actingAs($manager)
        ->test(ListStockLevels::class)
        ->assertActionVisible(TestAction::make('dispose_damage')->table($stock))
        ->callAction(TestAction::make('dispose_damage')->table($stock), data: [
            'quantity' => 1,
            'reason' => 'Scrapped',
        ])
        ->assertHasNoActionErrors();

    expect((float) $stock->fresh()->on_hand_quantity)->toBe(4.0)
        ->and((float) $stock->fresh()->damaged_quantity)->toBe(0.0)
        ->and((float) $stock->fresh()->available_quantity)->toBe(4.0);
});

it('offers matching serialized devices and denies damage operations without authorization', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $stock = InventoryStock::factory()->for($warehouse)->for($variant)->create();
    $available = SerializedInventoryUnit::factory()->for($warehouse)->for($variant)->create([
        'serial_number' => 'SER-AVAILABLE',
        'iot_number' => 'IOT-AVAILABLE',
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    $damaged = SerializedInventoryUnit::factory()->for($warehouse)->for($variant)->create([
        'serial_number' => 'SER-DAMAGED',
        'iot_number' => null,
        'status' => SerializedInventoryUnitStatus::Damaged,
    ]);
    $serializedOptions = new ReflectionMethod(StockDamageActions::class, 'serializedOptions');
    $actor = new ReflectionMethod(StockDamageActions::class, 'actor');
    $integerKey = new ReflectionMethod(StockDamageActions::class, 'integerKey');
    $ensureSupported = new ReflectionMethod(StockDamageActions::class, 'ensureSupported');

    expect($serializedOptions->invoke(null, $stock, MovementType::Damage))->toBe([
        $available->getKey() => 'SER-AVAILABLE / IOT-AVAILABLE',
    ])->and($serializedOptions->invoke(null, $stock, MovementType::DamageRecovery))->toBe([
        $damaged->getKey() => 'SER-DAMAGED',
    ])->and(fn (): mixed => $integerKey->invoke(null, new SerializedInventoryUnit))->toThrow(LogicException::class)
        ->and(fn (): mixed => $ensureSupported->invoke(null, MovementType::Receipt))->toThrow(LogicException::class)
        ->and(fn (): mixed => $actor->invoke(null))->toThrow(AuthorizationException::class);
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
