<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Models\InventoryAdjustmentItem;
use App\Models\Package;
use App\Models\PackageType;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function createInventoryPackageManager(): User
{
    $role = Role::firstOrCreate(['name' => 'inventory-package-manager', 'guard_name' => 'web']);
    $role->givePermissionTo([
        InventoryPermission::PackageView->value,
        InventoryPermission::PackageManage->value,
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('creates a package with a type and warehouse and lists it', function (): void {
    $admin = createInventoryPackageManager();
    $type = PackageType::factory()->create();
    $warehouse = Warehouse::factory()->create();

    Livewire::actingAs($admin)
        ->test(CreatePackage::class)
        ->fillForm([
            'name' => 'Crate 1',
            'package_type_id' => $type->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $package = Package::query()->where('name', 'Crate 1')->firstOrFail();

    expect($package->package_type_id)->toBe($type->getKey())
        ->and($package->warehouse_id)->toBe($warehouse->getKey());

    Livewire::actingAs($admin)
        ->test(ListPackages::class)
        ->assertCanSeeTableRecords([$package]);
});

it('requires a name, package type, and warehouse to create a package', function (): void {
    $admin = createInventoryPackageManager();

    Livewire::actingAs($admin)
        ->test(CreatePackage::class)
        ->fillForm([
            'name' => '',
            'package_type_id' => null,
            'warehouse_id' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['name', 'package_type_id', 'warehouse_id']);

    expect(Package::query()->count())->toBe(0);
});

it('deactivates a package', function (): void {
    $admin = createInventoryPackageManager();
    $package = Package::factory()->create(['is_active' => true]);

    Livewire::actingAs($admin)
        ->test(EditPackage::class, ['record' => $package->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($package->refresh()->is_active)->toBeFalse();
});

it('shows package details', function (): void {
    $admin = createInventoryPackageManager();
    $package = Package::factory()->create(['name' => 'Crate View']);

    Livewire::actingAs($admin)
        ->test(ViewPackage::class, ['record' => $package->getKey()])
        ->assertOk()
        ->assertSee($package->name);
});

it('leaves the warehouse field enabled for an unreferenced package', function (): void {
    $admin = createInventoryPackageManager();
    $package = Package::factory()->create();

    expect($package->isReferenced())->toBeFalse();

    Livewire::actingAs($admin)
        ->test(EditPackage::class, ['record' => $package->getKey()])
        ->assertFormFieldExists('warehouse_id');
});

it('disables the warehouse field once a package is referenced by an adjustment item', function (): void {
    $admin = createInventoryPackageManager();
    $package = Package::factory()->create();
    InventoryAdjustmentItem::factory()->create(['package_id' => $package->getKey()]);

    expect($package->isReferenced())->toBeTrue();

    $otherWarehouse = Warehouse::factory()->create();

    Livewire::actingAs($admin)
        ->test(EditPackage::class, ['record' => $package->getKey()])
        ->fillForm(['warehouse_id' => $otherWarehouse->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($package->refresh()->warehouse_id)->not->toBe($otherWarehouse->getKey());
});

it('reports a package referenced through a transfer item', function (): void {
    $package = Package::factory()->create();
    StockTransferItem::factory()->create(['package_id' => $package->getKey()]);

    expect($package->isReferenced())->toBeTrue();
});

it('reports a new unsaved package as never referenced', function (): void {
    $package = Package::factory()->make();

    expect($package->isReferenced())->toBeFalse();
});

it('treats a null package id as belonging to any warehouse', function (): void {
    $warehouse = Warehouse::factory()->create();

    expect(Package::belongsToWarehouse(null, $warehouse->getKey()))->toBeTrue();
});

it('checks whether a package belongs to a specific warehouse', function (): void {
    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $package = Package::factory()->for($warehouse)->create();

    expect(Package::belongsToWarehouse($package->getKey(), $warehouse->getKey()))->toBeTrue()
        ->and(Package::belongsToWarehouse($package->getKey(), $otherWarehouse->getKey()))->toBeFalse();
});

it('blocks deleting a package referenced by a transfer item and allows deactivation instead', function (): void {
    $admin = createInventoryPackageManager();
    $package = Package::factory()->create();
    StockTransferItem::factory()->create(['package_id' => $package->getKey()]);

    expect($admin->can('delete', $package))->toBeFalse();

    Livewire::actingAs($admin)
        ->test(EditPackage::class, ['record' => $package->getKey()])
        ->assertActionHidden(DeleteAction::class);

    expect($package->fresh()->trashed())->toBeFalse();
});

it('allows deleting and restoring an unreferenced package as a reversible soft delete', function (): void {
    $admin = createInventoryPackageManager();
    $package = Package::factory()->create();

    expect($admin->can('delete', $package))->toBeTrue();

    Livewire::actingAs($admin)
        ->test(EditPackage::class, ['record' => $package->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified();

    expect($package->fresh()->trashed())->toBeTrue();
    expect(Package::withTrashed()->find($package->id))->not->toBeNull();

    Livewire::actingAs($admin)
        ->test(ListPackages::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$package->fresh()]);

    Livewire::actingAs($admin)
        ->test(EditPackage::class, ['record' => $package->getKey()])
        ->callAction(RestoreAction::class)
        ->assertNotified();

    expect($package->fresh()->trashed())->toBeFalse();
});

it('never allows force deleting a package', function (): void {
    $admin = createInventoryPackageManager();
    $package = Package::factory()->create();

    expect($admin->can('forceDelete', $package))->toBeFalse();

    Livewire::actingAs($admin)
        ->test(EditPackage::class, ['record' => $package->getKey()])
        ->assertActionHidden(ForceDeleteAction::class);
});

it('denies package access without the package view permission', function (): void {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get(PackageResource::getUrl('index'))->assertForbidden();
});

it('grants package list access with the package view permission', function (): void {
    $admin = createInventoryPackageManager();

    $this->actingAs($admin)->get(PackageResource::getUrl('index'))->assertOk();
});

it('denies every package bulk mutation', function (): void {
    $admin = createInventoryPackageManager();
    Package::factory()->create();

    expect(PackageResource::canDeleteAny())->toBeFalse()
        ->and(PackageResource::canForceDeleteAny())->toBeFalse()
        ->and(PackageResource::canRestoreAny())->toBeFalse();

    $component = Livewire::actingAs($admin)
        ->test(ListPackages::class);

    expect($component->instance()->getTable()->getBulkActions())->toBeEmpty();
});

it('filters packages by type, warehouse, and active status', function (): void {
    $admin = createInventoryPackageManager();
    $type = PackageType::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $matching = Package::factory()->for($type, 'packageType')->for($warehouse)->create(['is_active' => true]);
    $other = Package::factory()->create(['is_active' => false]);

    Livewire::actingAs($admin)
        ->test(ListPackages::class)
        ->filterTable('package_type_id', $type->getKey())
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::actingAs($admin)
        ->test(ListPackages::class)
        ->filterTable('warehouse_id', $warehouse->getKey())
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::actingAs($admin)
        ->test(ListPackages::class)
        ->filterTable('is_active', true)
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

it('reaches a soft-deleted package through the edit route binding', function (): void {
    $admin = createInventoryPackageManager();
    $trashedPackage = Package::factory()->create();
    $trashedPackage->delete();

    $this->actingAs($admin)
        ->get(PackageResource::getUrl('edit', ['record' => $trashedPackage]))
        ->assertOk();
});
