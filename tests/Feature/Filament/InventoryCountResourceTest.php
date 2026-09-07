<?php

declare(strict_types=1);

use App\Enums\CountScope;
use App\Enums\InventoryPermission;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryCounts\Pages\CreateInventoryCount;
use App\Filament\Resources\InventoryCounts\Pages\ListInventoryCounts;
use App\Filament\Resources\InventoryCounts\Pages\ViewInventoryCount;
use App\Filament\Resources\InventoryCounts\RelationManagers\InventoryCountLinesRelationManager;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryCount;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function inventoryCountActor(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        InventoryPermission::CountView->value,
        InventoryPermission::CountOpen->value,
        InventoryPermission::CountRecord->value,
        InventoryPermission::CountConfirm->value,
    ]);

    return $user;
}

/** @return array{0: ProductVariant, 1: Warehouse} */
function seededCountStock(): array
{
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'available_quantity' => '10.000000',
    ]);

    InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '10.000000',
        'reserved_base_quantity' => '0.000000',
    ]);

    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
    ]);

    InventoryLotBalance::query()->firstOrNew([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable->value,
    ])->forceFill([
        'on_hand_base_quantity' => '10.000000',
        'reserved_base_quantity' => '0.000000',
    ])->save();

    return [$variant, $warehouse];
}

it('opens a count from the create form and renders it on the list and view pages', function (): void {
    [, $warehouse] = seededCountStock();
    $actor = inventoryCountActor();

    Livewire::actingAs($actor)
        ->test(CreateInventoryCount::class)
        ->fillForm([
            'warehouse_id' => $warehouse->getKey(),
            'scope_type' => CountScope::Warehouse->value,
            'conditions' => [StockCondition::Saleable->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $count = InventoryCount::query()->where('warehouse_id', $warehouse->getKey())->sole();

    Livewire::actingAs($actor)
        ->test(ListInventoryCounts::class)
        ->assertCanSeeTableRecords([$count]);

    Livewire::actingAs($actor)
        ->test(ViewInventoryCount::class, ['record' => $count->getKey()])
        ->assertSuccessful();
});

it('records a count from the lines relation manager', function (): void {
    [, $warehouse] = seededCountStock();
    $actor = inventoryCountActor();

    Livewire::actingAs($actor)
        ->test(CreateInventoryCount::class)
        ->fillForm([
            'warehouse_id' => $warehouse->getKey(),
            'scope_type' => CountScope::Warehouse->value,
            'conditions' => [StockCondition::Saleable->value],
        ])
        ->call('create');

    $count = InventoryCount::query()->where('warehouse_id', $warehouse->getKey())->sole();
    $line = $count->lines()->sole();

    Livewire::actingAs($actor)
        ->test(InventoryCountLinesRelationManager::class, [
            'ownerRecord' => $count,
            'pageClass' => ViewInventoryCount::class,
        ])
        ->assertCanSeeTableRecords([$line])
        ->callAction(TestAction::make('record_count')->table($line), data: ['quantity' => '10'])
        ->assertHasNoActionErrors();

    expect($line->refresh()->counted_base_quantity)->toBe('10.000000');
});
