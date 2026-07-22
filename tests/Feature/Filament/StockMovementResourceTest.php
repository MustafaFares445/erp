<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Pages\ViewStockMovement;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\InventoryMovement;
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

function createMovementViewer(): User
{
    $role = Role::firstOrCreate(['name' => 'movement-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::MovementView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('shows a read-only movement ledger with signed quantities', function (): void {
    $admin = createMovementViewer();
    $increase = InventoryMovement::factory()->return()->create(['quantity' => '5.000']);
    $decrease = InventoryMovement::factory()->sale()->create(['quantity' => '-3.000']);

    Livewire::actingAs($admin)
        ->test(ListStockMovements::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$increase, $decrease])
        ->assertSee('+5.000')
        ->assertSee('-3.000');
});

it('exposes no movement write actions', function (): void {
    $admin = createMovementViewer();
    $movement = InventoryMovement::factory()->create();

    expect(StockMovementResource::canCreate())->toBeFalse()
        ->and(StockMovementResource::getNavigationLabel())->toBe('Stock Movements')
        ->and(StockMovementResource::canDeleteAny())->toBeFalse()
        ->and(StockMovementResource::canForceDeleteAny())->toBeFalse()
        ->and(StockMovementResource::canRestoreAny())->toBeFalse();

    $component = Livewire::actingAs($admin)
        ->test(ListStockMovements::class)
        ->assertCanSeeTableRecords([$movement]);

    expect($component->instance()->getTable()->getActions())->toContainOnlyInstancesOf(ViewAction::class)
        ->and($component->instance()->getTable()->getHeaderActions())->toBeEmpty()
        ->and($component->instance()->getTable()->getBulkActions())->toBeEmpty();
});

it('filters movements by type, warehouse, variant, date, and source type', function (): void {
    $admin = createMovementViewer();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $matchingMovement = InventoryMovement::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'movement_type' => MovementType::Transfer,
        'source_type' => 'delivery_note',
        'source_id' => 42,
        'created_at' => now()->subDay(),
    ]);
    $otherMovement = InventoryMovement::factory()->create([
        'movement_type' => MovementType::Sale,
        'source_type' => 'invoice',
        'created_at' => now()->subWeeks(2),
    ]);

    Livewire::actingAs($admin)
        ->test(ListStockMovements::class)
        ->filterTable('movement_type', MovementType::Transfer->value)
        ->filterTable('warehouse_id', $warehouse->id)
        ->filterTable('product_variant_id', $variant->id)
        ->filterTable('created_at', [
            'from' => now()->subDays(2)->toDateString(),
            'until' => now()->toDateString(),
        ])
        ->filterTable('source_type', 'delivery_note')
        ->assertCanSeeTableRecords([$matchingMovement])
        ->assertCanNotSeeTableRecords([$otherMovement]);
});

it('shows a source as a read-only cross-module link on the movement view', function (): void {
    $admin = createMovementViewer();
    $movement = InventoryMovement::factory()
        ->fromSource('delivery_note', 42)
        ->create();

    Livewire::actingAs($admin)
        ->test(ViewStockMovement::class, ['record' => $movement->getKey()])
        ->assertOk()
        ->assertSee('delivery_note #42');
});

it('denies the movement resource without the movement view permission', function (): void {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get(StockMovementResource::getUrl('index'))->assertForbidden();
});

it('does not create a fake link when the source resource is unavailable', function (string $sourceType): void {
    $movement = InventoryMovement::factory()->fromSource($sourceType, 42)->create();

    expect(StockMovementsTable::sourceUrl($movement))->toBeNull();
})->with([
    'delivery note' => 'delivery_note',
    'invoice' => 'invoice',
    'credit note' => 'credit_note',
    'adjustment' => 'adjustment',
    'transfer' => 'transfer',
]);

it('does not link an absent or unknown source', function (): void {
    $absentSource = InventoryMovement::factory()->create([
        'source_type' => 'delivery_note',
        'source_id' => null,
    ]);
    $unknownSource = InventoryMovement::factory()->fromSource('legacy_source', 42)->create();

    expect(StockMovementsTable::sourceUrl($absentSource))->toBeNull()
        ->and(StockMovementsTable::sourceUrl($unknownSource))->toBeNull();
});

it('denies every direct stock and movement write ability', function (): void {
    $admin = createMovementViewer();
    $stock = InventoryStock::factory()->create();
    $movement = InventoryMovement::factory()->create();

    expect($admin->can('create', InventoryStock::class))->toBeFalse()
        ->and($admin->can('update', $stock))->toBeFalse()
        ->and($admin->can('delete', $stock))->toBeFalse()
        ->and($admin->can('forceDelete', $stock))->toBeFalse()
        ->and($admin->can('restore', $stock))->toBeFalse()
        ->and($admin->can('replicate', $stock))->toBeFalse()
        ->and($admin->can('reorder', InventoryStock::class))->toBeFalse()
        ->and($admin->can('create', InventoryMovement::class))->toBeFalse()
        ->and($admin->can('update', $movement))->toBeFalse()
        ->and($admin->can('delete', $movement))->toBeFalse();
});
