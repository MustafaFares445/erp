<?php

declare(strict_types=1);

use App\Data\Inventory\TransferData;
use App\Enums\InventoryPermission;
use App\Filament\Resources\Transfers\Pages\CreateTransfer;
use App\Filament\Resources\Transfers\Pages\EditTransfer;
use App\Filament\Resources\Transfers\Pages\ListTransfers;
use App\Filament\Resources\Transfers\Pages\ViewTransfer;
use App\Filament\Resources\Transfers\RelationManagers\TransferItemsRelationManager;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function createTransferPreparer(): User
{
    $role = Role::firstOrCreate(['name' => 'transfer-preparer', 'guard_name' => 'web']);
    $role->givePermissionTo([
        InventoryPermission::TransferView->value,
        InventoryPermission::TransferCreate->value,
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function createTransferApprover(): User
{
    $role = Role::firstOrCreate(['name' => 'transfer-approver', 'guard_name' => 'web']);
    $role->givePermissionTo([
        InventoryPermission::TransferView->value,
        InventoryPermission::TransferCreate->value,
        InventoryPermission::TransferConfirm->value,
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('creates a draft transfer with items and touches no stock or ledger', function (): void {
    $admin = createTransferPreparer();
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    Livewire::actingAs($admin)
        ->test(CreateTransfer::class)
        ->fillForm([
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'notes' => 'Restock west branch',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $transfer = StockTransfer::query()->where('from_warehouse_id', $from->id)->firstOrFail();

    expect($transfer->status->value)->toBe('draft')
        ->and($transfer->transfer_number)->toBeNull();

    Livewire::actingAs($admin)
        ->test(TransferItemsRelationManager::class, [
            'ownerRecord' => $transfer,
            'pageClass' => EditTransfer::class,
        ])
        ->callAction(TestAction::make('create')->table(), [
            'product_variant_id' => $variant->id,
            'quantity' => 4,
        ])
        ->assertHasNoFormErrors();

    expect($transfer->items()->count())->toBe(1)
        ->and(InventoryStock::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);

    expect(AuditLog::query()->where('action', 'inventory.transfer.created')->where('entity_id', $transfer->id)->exists())->toBeTrue();
});

it('rejects the same warehouse as source and destination', function (): void {
    $admin = createTransferPreparer();
    $warehouse = Warehouse::factory()->create();

    Livewire::actingAs($admin)
        ->test(CreateTransfer::class)
        ->fillForm([
            'from_warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $warehouse->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['to_warehouse_id']);

    expect(StockTransfer::query()->count())->toBe(0);
});

it('rejects a zero or negative quantity on an item line', function (): void {
    $admin = createTransferPreparer();
    $transfer = StockTransfer::factory()->create();

    Livewire::actingAs($admin)
        ->test(TransferItemsRelationManager::class, [
            'ownerRecord' => $transfer,
            'pageClass' => EditTransfer::class,
        ])
        ->callAction(TestAction::make('create')->table(), [
            'product_variant_id' => ProductVariant::factory()->create()->id,
            'quantity' => 0,
        ])
        ->assertHasFormErrors(['quantity']);

    expect($transfer->items()->count())->toBe(0);
});

it('populates created_by from the acting administrator', function (): void {
    $admin = createTransferPreparer();
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();

    Livewire::actingAs($admin)
        ->test(CreateTransfer::class)
        ->fillForm([
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $transfer = StockTransfer::query()->where('from_warehouse_id', $from->id)->firstOrFail();

    expect($transfer->created_by)->toBe($admin->id);
});

it('writes an edited audit row when item lines change on a draft', function (): void {
    $admin = createTransferPreparer();
    $transfer = StockTransfer::factory()->create();
    $variant = ProductVariant::factory()->create();

    Livewire::actingAs($admin)
        ->test(TransferItemsRelationManager::class, [
            'ownerRecord' => $transfer,
            'pageClass' => EditTransfer::class,
        ])
        ->callAction(TestAction::make('create')->table(), [
            'product_variant_id' => $variant->id,
            'quantity' => 3,
        ])
        ->assertHasNoFormErrors();

    expect(AuditLog::query()->where('action', 'inventory.transfer.edited')->where('entity_id', $transfer->id)->exists())->toBeTrue();
});

it('denies transfer access without the view permission', function (): void {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get(TransferResource::getUrl('index'))->assertForbidden();
});

it('denies transfer creation without the create permission', function (): void {
    $role = Role::firstOrCreate(['name' => 'transfer-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::TransferView->value);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $this->actingAs($admin)->get(TransferResource::getUrl('create'))->assertForbidden();
});

it('dispatches a draft transfer and receives it through the view page actions', function (): void {
    $approver = createTransferApprover();
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'available_quantity' => '10.000']);
    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '4.000']);

    Livewire::actingAs($approver)
        ->test(ViewTransfer::class, ['record' => $transfer->getKey()])
        ->callAction('dispatch')
        ->assertNotified();

    expect($transfer->fresh()->status->value)->toBe('dispatched')
        ->and((float) InventoryStock::query()->where('warehouse_id', $from->id)->value('on_hand_quantity'))->toBe(6.0)
        ->and(InventoryStock::query()->where('warehouse_id', $to->id)->doesntExist())->toBeTrue();

    Livewire::actingAs($approver)
        ->test(ViewTransfer::class, ['record' => $transfer->getKey()])
        ->callAction('receive')
        ->assertNotified();

    expect($transfer->fresh()->status->value)->toBe('received')
        ->and((float) InventoryStock::query()->where('warehouse_id', $to->id)->value('on_hand_quantity'))->toBe(4.0);
});

it('hides edit, delete, and dispatch once a transfer is received', function (): void {
    $approver = createTransferApprover();
    $transfer = StockTransfer::factory()->confirmed()->create();

    Livewire::actingAs($approver)
        ->test(ViewTransfer::class, ['record' => $transfer->getKey()])
        ->assertActionHidden('dispatch')
        ->assertActionHidden(EditAction::class);

    $this->actingAs($approver)
        ->get(TransferResource::getUrl('edit', ['record' => $transfer]))
        ->assertForbidden();

    expect($approver->can('update', $transfer))->toBeFalse()
        ->and($approver->can('delete', $transfer))->toBeFalse()
        ->and($approver->can('forceDelete', $transfer))->toBeFalse();
});

it('hides the dispatch action from a preparer without the confirm permission', function (): void {
    $preparer = createTransferPreparer();
    $transfer = StockTransfer::factory()->create(['created_by' => $preparer->id]);

    Livewire::actingAs($preparer)
        ->test(ViewTransfer::class, ['record' => $transfer->getKey()])
        ->assertActionHidden('dispatch');

    expect($preparer->can('confirm', $transfer))->toBeFalse();
});

it('shows the dispatch action to an approver on a draft transfer', function (): void {
    $approver = createTransferApprover();
    $transfer = StockTransfer::factory()->create();

    Livewire::actingAs($approver)
        ->test(ViewTransfer::class, ['record' => $transfer->getKey()])
        ->assertActionVisible('dispatch');
});

it('allows discarding a draft as a recoverable soft delete and restoring it via the trashed view', function (): void {
    $preparer = createTransferPreparer();
    $transfer = StockTransfer::factory()->create();

    expect($preparer->can('delete', $transfer))->toBeTrue();

    Livewire::actingAs($preparer)
        ->test(EditTransfer::class, ['record' => $transfer->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified();

    expect($transfer->fresh()->trashed())->toBeTrue();
    expect(StockTransfer::withTrashed()->find($transfer->id))->not->toBeNull();

    expect(AuditLog::query()->where('action', 'inventory.transfer.discarded')->where('entity_id', $transfer->id)->exists())->toBeTrue();

    Livewire::actingAs($preparer)
        ->test(ListTransfers::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$transfer->fresh()]);

    Livewire::actingAs($preparer)
        ->test(EditTransfer::class, ['record' => $transfer->getKey()])
        ->callAction(RestoreAction::class)
        ->assertNotified();

    expect($transfer->fresh()->trashed())->toBeFalse()
        ->and($transfer->fresh()->isDraft())->toBeTrue();

    expect(AuditLog::query()->where('action', 'inventory.transfer.restored')->where('entity_id', $transfer->id)->exists())->toBeTrue();
});

it('lists transfers with number, warehouses, status, and creator', function (): void {
    $admin = createTransferApprover();
    $draft = StockTransfer::factory()->create();
    $confirmed = StockTransfer::factory()->confirmed()->create();

    Livewire::actingAs($admin)
        ->test(ListTransfers::class)
        ->assertCanSeeTableRecords([$draft, $confirmed]);
});

it('filters transfers by status and warehouse', function (): void {
    $admin = createTransferApprover();
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();
    $draftA = StockTransfer::factory()->for($warehouseA, 'fromWarehouse')->create();
    $confirmedB = StockTransfer::factory()->confirmed()->for($warehouseB, 'fromWarehouse')->create();

    Livewire::actingAs($admin)
        ->test(ListTransfers::class)
        ->filterTable('status', 'draft')
        ->assertCanSeeTableRecords([$draftA])
        ->assertCanNotSeeTableRecords([$confirmedB]);
});

it('filters transfers by a created_at date range', function (): void {
    $admin = createTransferApprover();
    $matching = StockTransfer::factory()->create(['created_at' => now()->subDay()]);
    $outOfRange = StockTransfer::factory()->create(['created_at' => now()->subWeeks(2)]);

    Livewire::actingAs($admin)
        ->test(ListTransfers::class)
        ->filterTable('created_at', [
            'from' => now()->subDays(2)->toDateString(),
            'until' => now()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$outOfRange]);
});

it('renders the transfers index page, including its navigation label', function (): void {
    $admin = createTransferApprover();

    $this->actingAs($admin)->get(TransferResource::getUrl('index'))->assertOk();
});

it('denies updating, deleting, and restoring a transfer without the create permission', function (): void {
    $viewer = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'transfer-read-only', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::TransferView->value);

    $viewer->assignRole($role);
    $draft = StockTransfer::factory()->create();

    expect($viewer->can('update', $draft))->toBeFalse()
        ->and($viewer->can('delete', $draft))->toBeFalse();
});

it('allows a preparer to restore a soft-deleted draft', function (): void {
    $preparer = createTransferPreparer();
    $draft = StockTransfer::factory()->create();

    expect($preparer->can('restore', $draft))->toBeTrue();
});

it('exposes the transfer draft validation rules and shape', function (): void {
    $data = new TransferData(
        from_warehouse_id: 1,
        to_warehouse_id: 2,
        notes: null,
        items: [['product_variant_id' => 1, 'quantity' => 4.0]],
    );

    expect($data->from_warehouse_id)->toBe(1)
        ->and(TransferData::rules())->toHaveKeys([
            'from_warehouse_id',
            'to_warehouse_id',
            'notes',
            'items',
            'items.*.product_variant_id',
            'items.*.quantity',
        ]);
});
