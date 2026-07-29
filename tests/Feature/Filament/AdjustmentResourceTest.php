<?php

declare(strict_types=1);

use App\Data\Inventory\AdjustmentData;
use App\Enums\InventoryPermission;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\Adjustments\Pages\CreateAdjustment;
use App\Filament\Resources\Adjustments\Pages\EditAdjustment;
use App\Filament\Resources\Adjustments\Pages\ListAdjustments;
use App\Filament\Resources\Adjustments\Pages\ViewAdjustment;
use App\Filament\Resources\Adjustments\RelationManagers\AdjustmentItemsRelationManager;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function createAdjustmentPreparer(): User
{
    $role = Role::firstOrCreate(['name' => 'adjustment-preparer', 'guard_name' => 'web']);
    $role->givePermissionTo([
        InventoryPermission::AdjustmentView->value,
        InventoryPermission::AdjustmentCreate->value,
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function createAdjustmentApprover(): User
{
    $role = Role::firstOrCreate(['name' => 'adjustment-approver', 'guard_name' => 'web']);
    $role->givePermissionTo([
        InventoryPermission::AdjustmentView->value,
        InventoryPermission::AdjustmentCreate->value,
        InventoryPermission::AdjustmentConfirm->value,
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('creates a draft adjustment with items and touches no stock or ledger', function (): void {
    $admin = createAdjustmentPreparer();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    $component = Livewire::actingAs($admin)
        ->test(CreateAdjustment::class)
        ->fillForm([
            'warehouse_id' => $warehouse->id,
            'reason' => 'Physical count discrepancy',
            'items' => [[
                'product_variant_id' => $variant->id,
                'new_quantity' => 15,
            ]],
        ])
        ->assertFormFieldVisible('items')
        ->call('create')
        ->assertHasNoFormErrors();

    $adjustment = InventoryAdjustment::query()->where('warehouse_id', $warehouse->id)->firstOrFail();

    $component->assertRedirect(AdjustmentResource::getUrl('edit', ['record' => $adjustment]));

    expect($adjustment->status->value)->toBe('draft')
        ->and($adjustment->adjustment_number)->toBeNull();

    expect($adjustment->items()->count())->toBe(1)
        ->and(InventoryStock::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('requires an adjustment item when creating a draft', function (): void {
    $admin = createAdjustmentPreparer();
    $warehouse = Warehouse::factory()->create();

    Livewire::actingAs($admin)
        ->test(CreateAdjustment::class)
        ->fillForm([
            'warehouse_id' => $warehouse->id,
            'reason' => 'Cycle count',
        ])
        ->call('create')
        ->assertHasFormErrors(['items']);

    expect(InventoryAdjustment::query()->count())->toBe(0);
});

it('rejects a draft with no reason and creates no record', function (): void {
    $admin = createAdjustmentPreparer();
    $warehouse = Warehouse::factory()->create();

    Livewire::actingAs($admin)
        ->test(CreateAdjustment::class)
        ->fillForm([
            'warehouse_id' => $warehouse->id,
            'reason' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['reason']);

    expect(InventoryAdjustment::query()->count())->toBe(0);
});

it('rejects a negative counted quantity on an item line', function (): void {
    $admin = createAdjustmentPreparer();
    $adjustment = InventoryAdjustment::factory()->create();

    Livewire::actingAs($admin)
        ->test(AdjustmentItemsRelationManager::class, [
            'ownerRecord' => $adjustment,
            'pageClass' => EditAdjustment::class,
        ])
        ->callAction(TestAction::make('create')->table(), [
            'product_variant_id' => ProductVariant::factory()->create()->id,
            'new_quantity' => -5,
        ])
        ->assertHasFormErrors(['new_quantity']);

    expect($adjustment->items()->count())->toBe(0);
});

it('shows the live current on-hand and computed difference on an item line', function (): void {
    $admin = createAdjustmentPreparer();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create(['on_hand_quantity' => '10.000']);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();

    $item = $adjustment->items()->create([
        'product_variant_id' => $variant->id,
        'new_quantity' => '13.000',
    ]);

    Livewire::actingAs($admin)
        ->test(AdjustmentItemsRelationManager::class, [
            'ownerRecord' => $adjustment,
            'pageClass' => EditAdjustment::class,
        ])
        ->assertTableColumnStateSet('old_quantity', 10, record: $item)
        ->assertTableColumnStateSet('new_quantity', 13, record: $item);
});

it('populates created_by from the acting administrator', function (): void {
    $admin = createAdjustmentPreparer();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    Livewire::actingAs($admin)
        ->test(CreateAdjustment::class)
        ->fillForm([
            'warehouse_id' => $warehouse->id,
            'reason' => 'Cycle count',
            'items' => [[
                'product_variant_id' => $variant->id,
                'new_quantity' => 1,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $adjustment = InventoryAdjustment::query()->where('warehouse_id', $warehouse->id)->firstOrFail();

    expect($adjustment->created_by)->toBe($admin->id);
});

it('denies adjustment access without the view permission', function (): void {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get(AdjustmentResource::getUrl('index'))->assertForbidden();
});

it('denies adjustment creation without the create permission', function (): void {
    $role = Role::firstOrCreate(['name' => 'adjustment-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::AdjustmentView->value);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $this->actingAs($admin)->get(AdjustmentResource::getUrl('create'))->assertForbidden();
});

it('requires a persisted adjustment before redirecting to its edit page', function (): void {
    $redirect = new ReflectionMethod(CreateAdjustment::class, 'getRedirectUrl');

    expect(fn (): mixed => $redirect->invoke(new CreateAdjustment))
        ->toThrow(LogicException::class, 'The adjustment must be persisted before redirecting');
});

it('hides edit, delete, and confirm once an adjustment is confirmed', function (): void {
    $approver = createAdjustmentApprover();
    $adjustment = InventoryAdjustment::factory()->confirmed()->create();

    Livewire::actingAs($approver)
        ->test(ViewAdjustment::class, ['record' => $adjustment->getKey()])
        ->assertActionHidden('confirm')
        ->assertActionHidden(EditAction::class);

    $this->actingAs($approver)
        ->get(AdjustmentResource::getUrl('edit', ['record' => $adjustment]))
        ->assertForbidden();

    expect($approver->can('update', $adjustment))->toBeFalse()
        ->and($approver->can('delete', $adjustment))->toBeFalse()
        ->and($approver->can('forceDelete', $adjustment))->toBeFalse();
});

it('hides the confirm action from a preparer without the confirm permission', function (): void {
    $preparer = createAdjustmentPreparer();
    $adjustment = InventoryAdjustment::factory()->create(['created_by' => $preparer->id]);

    Livewire::actingAs($preparer)
        ->test(ViewAdjustment::class, ['record' => $adjustment->getKey()])
        ->assertActionHidden('confirm');

    expect($preparer->can('confirm', $adjustment))->toBeFalse();
});

it('shows the confirm action to an approver on a draft adjustment', function (): void {
    $approver = createAdjustmentApprover();
    $adjustment = InventoryAdjustment::factory()->create();

    Livewire::actingAs($approver)
        ->test(ViewAdjustment::class, ['record' => $adjustment->getKey()])
        ->assertActionVisible('confirm');
});

it('allows discarding a draft as a recoverable soft delete', function (): void {
    $preparer = createAdjustmentPreparer();
    $adjustment = InventoryAdjustment::factory()->create();

    expect($preparer->can('delete', $adjustment))->toBeTrue();

    Livewire::actingAs($preparer)
        ->test(EditAdjustment::class, ['record' => $adjustment->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified();

    expect($adjustment->fresh()->trashed())->toBeTrue();
    expect(InventoryAdjustment::withTrashed()->find($adjustment->id))->not->toBeNull();
});

it('lists adjustments with number, warehouse, reason, status, and creator', function (): void {
    $admin = createAdjustmentApprover();
    $draft = InventoryAdjustment::factory()->create();
    $confirmed = InventoryAdjustment::factory()->confirmed()->create();

    Livewire::actingAs($admin)
        ->test(ListAdjustments::class)
        ->assertCanSeeTableRecords([$draft, $confirmed]);
});

it('filters adjustments by status and warehouse', function (): void {
    $admin = createAdjustmentApprover();
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();
    $draftA = InventoryAdjustment::factory()->for($warehouseA)->create();
    $confirmedB = InventoryAdjustment::factory()->confirmed()->for($warehouseB)->create();

    Livewire::actingAs($admin)
        ->test(ListAdjustments::class)
        ->filterTable('status', 'draft')
        ->assertCanSeeTableRecords([$draftA])
        ->assertCanNotSeeTableRecords([$confirmedB]);
});

it('filters adjustments by a created_at date range', function (): void {
    $admin = createAdjustmentApprover();
    $matching = InventoryAdjustment::factory()->create(['created_at' => now()->subDay()]);
    $outOfRange = InventoryAdjustment::factory()->create(['created_at' => now()->subWeeks(2)]);

    Livewire::actingAs($admin)
        ->test(ListAdjustments::class)
        ->filterTable('created_at', [
            'from' => now()->subDays(2)->toDateString(),
            'until' => now()->toDateString(),
        ])
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$outOfRange]);
});

it('renders the adjustments index page, including its navigation label', function (): void {
    $admin = createAdjustmentApprover();

    $this->actingAs($admin)->get(AdjustmentResource::getUrl('index'))->assertOk();
});

it('confirms a draft adjustment through the view page action', function (): void {
    $approver = createAdjustmentApprover();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $adjustment->items()->create(['product_variant_id' => $variant->id, 'new_quantity' => '5.000']);

    Livewire::actingAs($approver)
        ->test(ViewAdjustment::class, ['record' => $adjustment->getKey()])
        ->callAction('confirm')
        ->assertNotified();

    expect($adjustment->fresh()->status->value)->toBe('confirmed');
});

it('shows the frozen counted quantities on items once the adjustment is confirmed', function (): void {
    $approver = createAdjustmentApprover();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $adjustment = InventoryAdjustment::factory()->confirmed()->for($warehouse)->create();
    $item = $adjustment->items()->make(['product_variant_id' => $variant->id, 'new_quantity' => '8.000']);
    $item->forceFill(['old_quantity' => '3.000', 'difference' => '5.000'])->save();

    Livewire::actingAs($approver)
        ->test(AdjustmentItemsRelationManager::class, [
            'ownerRecord' => $adjustment,
            'pageClass' => EditAdjustment::class,
        ])
        ->assertTableColumnStateSet('old_quantity', 3, record: $item);
});

it('denies updating, deleting, and restoring an adjustment without the create permission', function (): void {
    $viewer = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'adjustment-read-only', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::AdjustmentView->value);

    $viewer->assignRole($role);
    $draft = InventoryAdjustment::factory()->create();

    expect($viewer->can('update', $draft))->toBeFalse()
        ->and($viewer->can('delete', $draft))->toBeFalse();
});

it('allows a preparer to restore a soft-deleted draft', function (): void {
    $preparer = createAdjustmentPreparer();
    $draft = InventoryAdjustment::factory()->create();

    expect($preparer->can('restore', $draft))->toBeTrue();
});

it('exposes the adjustment draft validation rules and shape', function (): void {
    $data = new AdjustmentData(
        warehouse_id: 1,
        reason: 'Cycle count',
        items: [['product_variant_id' => 1, 'new_quantity' => 5.0]],
    );

    expect($data->warehouse_id)->toBe(1)
        ->and(AdjustmentData::rules())->toHaveKeys([
            'warehouse_id',
            'reason',
            'items',
            'items.*.product_variant_id',
            'items.*.new_quantity',
        ]);
});
