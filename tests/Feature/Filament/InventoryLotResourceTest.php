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
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    InventorySetting::query()->create([
        'default_markup_percent' => 0,
        'expiry_alert_days' => 30,
    ]);
});

it('orders lots by nearest expiry and shows computed availability', function (): void {
    $viewer = lotViewer();
    $later = InventoryLot::factory()->create([
        'expires_at' => today()->addDays(20),
        'on_hand_quantity' => 10,
        'reserved_quantity' => 2,
    ]);
    $nearest = InventoryLot::factory()->create([
        'expires_at' => today()->addDays(2),
        'on_hand_quantity' => 5,
        'reserved_quantity' => 1,
    ]);
    $withoutExpiry = InventoryLot::factory()->create(['expires_at' => null]);

    expect($nearest->daysRemaining())->toBe(2)
        ->and($nearest->expiryState())->toBe('expiring')
        ->and($later->expiryState())->toBe('expiring')
        ->and($withoutExpiry->expiryState())->toBe('no_expiry');

    Livewire::actingAs($viewer)
        ->test(ListInventoryLots::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$nearest, $later, $withoutExpiry], inOrder: true)
        ->assertTableColumnStateSet('available_quantity', 4.0, $nearest);
});

it('shows saleable lot availability without counting quarantine or damaged quantity', function (): void {
    $viewer = lotViewer();
    $lot = InventoryLot::factory()->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '1.000000',
        'expires_at' => today()->addDays(20),
    ]);

    foreach ([
        StockCondition::Saleable->value => ['4.000000', '1.000000'],
        StockCondition::Quarantine->value => ['2.000000', '0.000000'],
        StockCondition::Damaged->value => ['4.000000', '0.000000'],
    ] as $condition => [$onHand, $reserved]) {
        InventoryLotBalance::query()->forceCreate([
            'inventory_lot_id' => $lot->getKey(),
            'warehouse_id' => $lot->warehouse_id,
            'stock_condition' => $condition,
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => $reserved,
        ]);
    }

    expect($lot->availableQuantity())->toBe(3.0)
        ->and($lot->conditionOnHandQuantity(StockCondition::Saleable))->toBe(4.0)
        ->and($lot->conditionOnHandQuantity(StockCondition::Quarantine))->toBe(2.0)
        ->and($lot->conditionOnHandQuantity(StockCondition::Damaged))->toBe(4.0);

    Livewire::actingAs($viewer)
        ->test(ListInventoryLots::class)
        ->assertTableColumnStateSet('saleable_quantity', 4.0, $lot)
        ->assertTableColumnStateSet('quarantine_quantity', 2.0, $lot)
        ->assertTableColumnStateSet('damaged_quantity', 4.0, $lot)
        ->assertTableColumnStateSet('available_quantity', 3.0, $lot);
});

it('filters lots by warehouse product and expiry state', function (): void {
    $viewer = lotViewer();
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $expired = InventoryLot::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'expires_at' => today()->subDay(),
    ]);
    $expiring = InventoryLot::factory()->create(['expires_at' => today()->addDays(10)]);
    $healthy = InventoryLot::factory()->create(['expires_at' => today()->addDays(60)]);

    Livewire::actingAs($viewer)
        ->test(ListInventoryLots::class)
        ->filterTable('warehouse_id', $warehouse->getKey())
        ->filterTable('product_id', $product->getKey())
        ->filterTable('expired')
        ->assertCanSeeTableRecords([$expired])
        ->assertCanNotSeeTableRecords([$expiring, $healthy]);

    Livewire::actingAs($viewer)
        ->test(ListInventoryLots::class)
        ->filterTable('expiring')
        ->assertCanSeeTableRecords([$expiring])
        ->assertCanNotSeeTableRecords([$expired, $healthy]);

    Livewire::actingAs($viewer)
        ->test(ListInventoryLots::class)
        ->filterTable('healthy')
        ->assertCanSeeTableRecords([$healthy])
        ->assertCanNotSeeTableRecords([$expired, $expiring]);
});

it('keeps the lot resource read only and stock-view protected', function (): void {
    $viewer = lotViewer();
    $lot = InventoryLot::factory()->create();

    expect(InventoryLotResource::canCreate())->toBeFalse()
        ->and(InventoryLotResource::canDeleteAny())->toBeFalse()
        ->and(InventoryLotResource::canForceDeleteAny())->toBeFalse()
        ->and(InventoryLotResource::canRestoreAny())->toBeFalse();

    $component = Livewire::actingAs($viewer)->test(ListInventoryLots::class);

    expect($component->instance()->getTable()->getActions())->toContainOnlyInstancesOf(ViewAction::class)
        ->and($component->instance()->getTable()->getHeaderActions())->toBeEmpty()
        ->and($component->instance()->getTable()->getBulkActions())->toBeEmpty();

    $this->actingAs(User::factory()->admin()->create())
        ->get(InventoryLotResource::getUrl('index'))
        ->assertForbidden();
});

function lotViewer(): User
{
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::StockView->value);

    return $viewer;
}
