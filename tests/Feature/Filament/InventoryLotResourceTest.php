<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Filament\Resources\InventoryLots\Pages\ListInventoryLots;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventorySetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\ViewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    InventorySetting::query()->create([
        'default_markup_percent' => 0,
        'expiry_alert_days' => 30,
    ]);
});

it('shows one stable lot identity with multi-warehouse aggregate balances', function (): void {
    $viewer = lotViewer();
    $variant = ProductVariant::factory()->grain()->create();
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();
    $lot = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create([
        'lot_number' => 'UI-MULTI',
        'expires_at' => today()->addDays(20),
    ]);

    foreach ([
        [$warehouseA, StockCondition::Saleable, '4.000000', '1.000000'],
        [$warehouseA, StockCondition::Damaged, '1.000000', '0.000000'],
        [$warehouseB, StockCondition::Saleable, '6.000000', '0.000000'],
        [$warehouseB, StockCondition::Quarantine, '2.000000', '0.000000'],
    ] as [$warehouse, $condition, $onHand, $reserved]) {
        InventoryLotBalance::query()->forceCreate([
            'inventory_lot_id' => $lot->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition,
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => $reserved,
        ]);
    }

    expect($lot->totalPhysicalQuantity())->toBe(13.0)
        ->and($lot->totalConditionOnHandQuantity(StockCondition::Saleable))->toBe(10.0)
        ->and($lot->totalConditionOnHandQuantity(StockCondition::Quarantine))->toBe(2.0)
        ->and($lot->totalConditionOnHandQuantity(StockCondition::Damaged))->toBe(1.0)
        ->and($lot->totalAvailableQuantity())->toBe(9.0)
        ->and($lot->warehouseCount())->toBe(2);

    Livewire::actingAs($viewer)
        ->test(ListInventoryLots::class)
        ->assertTableColumnStateSet('total_physical', 13.0, $lot)
        ->assertTableColumnStateSet('saleable_quantity', 10.0, $lot)
        ->assertTableColumnStateSet('quarantine_quantity', 2.0, $lot)
        ->assertTableColumnStateSet('damaged_quantity', 1.0, $lot)
        ->assertTableColumnStateSet('available_quantity', 9.0, $lot)
        ->assertTableColumnStateSet('warehouse_count', 2, $lot);
});

it('filters stable lot identities through their warehouse balances', function (): void {
    $viewer = lotViewer();
    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    $matching = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create([
        'expires_at' => today()->subDay(),
    ]);
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $matching->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '1.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    $other = InventoryLot::factory()->canonical()->for($variant, 'productVariant')->create([
        'expires_at' => today()->subDay(),
    ]);
    InventoryLotBalance::query()->forceCreate([
        'inventory_lot_id' => $other->getKey(),
        'warehouse_id' => $otherWarehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '1.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    $component = Livewire::actingAs($viewer)
        ->test(ListInventoryLots::class)
        ->assertCanSeeTableRecords([$matching, $other]);

    $component
        ->filterTable('warehouse_id', $warehouse->getKey())
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other])
        ->filterTable('product_id', $product->getKey())
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other])
        ->filterTable('expired')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

it('hides legacy lot aliases and keeps the canonical lot resource read only', function (): void {
    $viewer = lotViewer();
    $canonical = InventoryLot::factory()->canonical()->create();
    $aliasId = DB::table('inventory_lots')->insertGetId([
        'product_variant_id' => $canonical->product_variant_id,
        'lot_number' => $canonical->lot_number,
        'normalized_lot_number' => null,
        'canonical_inventory_lot_id' => $canonical->getKey(),
        'expires_at' => $canonical->expires_at,
        'warehouse_id' => null,
        'on_hand_quantity' => 0,
        'reserved_quantity' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $alias = InventoryLot::query()->findOrFail($aliasId);

    Livewire::actingAs($viewer)
        ->test(ListInventoryLots::class)
        ->assertCanSeeTableRecords([$canonical])
        ->assertCanNotSeeTableRecords([$alias]);

    expect(InventoryLotResource::canCreate())->toBeFalse()
        ->and(InventoryLotResource::canDeleteAny())->toBeFalse()
        ->and(InventoryLotResource::canForceDeleteAny())->toBeFalse()
        ->and(InventoryLotResource::canRestoreAny())->toBeFalse();

    $component = Livewire::actingAs($viewer)->test(ListInventoryLots::class);

    expect($component->instance()->getTable()->getActions())->toContainOnlyInstancesOf(ViewAction::class)
        ->and($component->instance()->getTable()->getHeaderActions())->toBeEmpty()
        ->and($component->instance()->getTable()->getBulkActions())->toBeEmpty();
});

function lotViewer(): User
{
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::StockView->value);

    return $viewer;
}
