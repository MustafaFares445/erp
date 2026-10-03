<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Filament\Resources\InventoryOperations\Pages\ListInventoryOperations;
use App\Filament\Resources\InventoryOperations\Pages\ListReceipts;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();

    $role = Role::firstOrCreate(['name' => 'inventory-list-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo([
        InventoryPermission::ReceiptView->value,
        InventoryPermission::DeliveryView->value,
        InventoryPermission::TransferView->value,
        InventoryPermission::StockView->value,
    ]);

    $this->user = User::factory()->create();
    $this->user->assignRole($role);
    $this->actingAs($this->user);
});

it('renders the operations list with presets and Starred in the tab bar', function (): void {
    Livewire::test(ListInventoryOperations::class)
        ->assertSeeHtml("selectTableView('preset', 'all')")
        ->assertSeeHtml("selectTableView('preset', 'open')")
        ->assertSeeHtml("selectTableView('preset', 'starred')");
});

it('renders the typed receipts list with the same tab bar', function (): void {
    Livewire::test(ListReceipts::class)
        ->assertSeeHtml("selectTableView('preset', 'all')")
        ->assertSeeHtml("selectTableView('preset', 'starred')");
});

it('stars an operation and lists it under the Starred tab', function (): void {
    [$starred, $other] = InventoryOperation::factory()->receipt()->count(2)->create()->all();

    Livewire::test(ListInventoryOperations::class)
        ->callTableColumnAction('is_favorited', $starred);

    expect($starred->isFavoritedBy($this->user))->toBeTrue()
        ->and($other->isFavoritedBy($this->user))->toBeFalse();

    Livewire::test(ListInventoryOperations::class)
        ->call('selectTableView', 'preset', 'starred')
        ->assertCanSeeTableRecords([$starred])
        ->assertCanNotSeeTableRecords([$other]);
});

it('narrows operations with the open and ready presets', function (): void {
    $draft = InventoryOperation::factory()->receipt()->create();
    $ready = InventoryOperation::factory()->receipt()->ready()->create();
    $done = InventoryOperation::factory()->receipt()->done()->create();

    Livewire::test(ListInventoryOperations::class)
        ->call('selectTableView', 'preset', 'open')
        ->assertCanSeeTableRecords([$draft, $ready])
        ->assertCanNotSeeTableRecords([$done])
        ->call('selectTableView', 'preset', 'ready')
        ->assertCanSeeTableRecords([$ready])
        ->assertCanNotSeeTableRecords([$draft, $done]);
});

it('filters operations with stage and warehouse query-builder rules', function (): void {
    $warehouse = Warehouse::factory()->create();
    $draft = InventoryOperation::factory()->receipt()->create(['destination_warehouse_id' => $warehouse->id]);
    $ready = InventoryOperation::factory()->receipt()->ready()->create();

    Livewire::test(ListInventoryOperations::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'r1' => [
                    'type' => 'stage',
                    'data' => ['operator' => 'is', 'settings' => ['values' => [OperationStage::Ready->value]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$ready])
        ->assertCanNotSeeTableRecords([$draft]);

    Livewire::test(ListInventoryOperations::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'r1' => [
                    'type' => 'destinationWarehouse',
                    'data' => ['operator' => 'isRelatedTo', 'settings' => ['value' => [$warehouse->id]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$ready]);
});

it('keeps the type scoping on the typed list while filtering', function (): void {
    $receipt = InventoryOperation::factory()->receipt()->ready()->create();
    $delivery = InventoryOperation::factory()->delivery()->ready()->create();

    Livewire::test(ListReceipts::class)
        ->assertCanSeeTableRecords([$receipt])
        ->assertCanNotSeeTableRecords([$delivery])
        ->filterTable('queryBuilder', [
            'rules' => [
                'r1' => [
                    'type' => 'stage',
                    'data' => ['operator' => 'is', 'settings' => ['values' => [OperationStage::Ready->value]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$receipt])
        ->assertCanNotSeeTableRecords([$delivery]);
});

it('groups operations without error', function (string $group): void {
    InventoryOperation::factory()->receipt()->count(2)->create();
    InventoryOperation::factory()->delivery()->create();

    Livewire::test(ListInventoryOperations::class)
        ->set('tableGrouping', $group)
        ->assertOk();
})->with(['stage', 'operation_type', 'sourceWarehouse.name', 'destinationWarehouse.name', 'scheduled_at']);

it('renders the stock levels list with the tab bar but no Starred tab', function (): void {
    Livewire::test(ListStockLevels::class)
        ->assertSeeHtml("selectTableView('preset', 'all')")
        ->assertSeeHtml("selectTableView('preset', 'low_stock')")
        ->assertDontSeeHtml("selectTableView('preset', 'starred')");
});

it('filters stock levels with query-builder rules and presets', function (): void {
    $warehouse = Warehouse::factory()->create();
    $inWarehouse = InventoryStock::factory()->create(['warehouse_id' => $warehouse->id, 'on_hand_quantity' => '12.000', 'available_quantity' => '12.000']);
    $elsewhere = InventoryStock::factory()->create(['on_hand_quantity' => '0.000', 'available_quantity' => '0.000']);

    Livewire::test(ListStockLevels::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'r1' => [
                    'type' => 'warehouse',
                    'data' => ['operator' => 'isRelatedTo', 'settings' => ['value' => [$warehouse->id]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$inWarehouse])
        ->assertCanNotSeeTableRecords([$elsewhere]);

    Livewire::test(ListStockLevels::class)
        ->filterTable('queryBuilder', [
            'rules' => [
                'r1' => [
                    'type' => 'on_hand_quantity',
                    'data' => ['operator' => 'isMax', 'settings' => ['number' => 0]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$elsewhere])
        ->assertCanNotSeeTableRecords([$inWarehouse]);

    Livewire::test(ListStockLevels::class)
        ->call('selectTableView', 'preset', 'empty')
        ->assertCanSeeTableRecords([$elsewhere])
        ->assertCanNotSeeTableRecords([$inWarehouse]);
});

it('groups stock levels without error', function (string $group): void {
    InventoryStock::factory()->count(2)->create();

    Livewire::test(ListStockLevels::class)
        ->set('tableGrouping', $group)
        ->assertOk();
})->with(['warehouse.name', 'productVariant.name', 'updated_at']);
