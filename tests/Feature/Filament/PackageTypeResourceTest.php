<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\PackageTypes\PackageTypeResource;
use App\Filament\Resources\PackageTypes\Pages\CreatePackageType;
use App\Filament\Resources\PackageTypes\Pages\EditPackageType;
use App\Filament\Resources\PackageTypes\Pages\ListPackageTypes;
use App\Filament\Resources\PackageTypes\Pages\ViewPackageType;
use App\Models\Package;
use App\Models\PackageType;
use App\Models\User;
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

function createPackageManager(): User
{
    $role = Role::firstOrCreate(['name' => 'package-manager', 'guard_name' => 'web']);
    $role->givePermissionTo([
        InventoryPermission::PackageView->value,
        InventoryPermission::PackageManage->value,
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('creates a package type with a unique code and lists it', function (): void {
    $admin = createPackageManager();

    Livewire::actingAs($admin)
        ->test(CreatePackageType::class)
        ->fillForm([
            'name' => 'Pallet',
            'code' => 'PLT-001',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $packageType = PackageType::query()->where('code', 'PLT-001')->firstOrFail();

    expect($packageType->name)->toBe('Pallet');

    Livewire::actingAs($admin)
        ->test(ListPackageTypes::class)
        ->assertCanSeeTableRecords([$packageType]);
});

it('rejects a duplicate package type code and creates no record', function (): void {
    $admin = createPackageManager();
    PackageType::factory()->create(['code' => 'PLT-DUP']);

    Livewire::actingAs($admin)
        ->test(CreatePackageType::class)
        ->fillForm([
            'name' => 'Another Pallet',
            'code' => 'PLT-DUP',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);

    expect(PackageType::query()->where('code', 'PLT-DUP')->count())->toBe(1);
});

it('deactivates a package type', function (): void {
    $admin = createPackageManager();
    $packageType = PackageType::factory()->create(['is_active' => true]);

    Livewire::actingAs($admin)
        ->test(EditPackageType::class, ['record' => $packageType->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($packageType->refresh()->is_active)->toBeFalse();
});

it('shows package type details', function (): void {
    $admin = createPackageManager();
    $packageType = PackageType::factory()->create(['code' => 'PLT-VIEW']);

    Livewire::actingAs($admin)
        ->test(ViewPackageType::class, ['record' => $packageType->getKey()])
        ->assertOk()
        ->assertSee($packageType->code);
});

it('blocks deleting a package type referenced by packages and allows deactivation instead', function (): void {
    $admin = createPackageManager();
    $packageType = PackageType::factory()->create();
    Package::factory()->for($packageType, 'packageType')->create();

    expect($admin->can('delete', $packageType))->toBeFalse();

    Livewire::actingAs($admin)
        ->test(EditPackageType::class, ['record' => $packageType->getKey()])
        ->assertActionHidden(DeleteAction::class);

    expect($packageType->fresh()->trashed())->toBeFalse();
});

it('allows deleting and restoring an unreferenced package type as a reversible soft delete', function (): void {
    $admin = createPackageManager();
    $packageType = PackageType::factory()->create();

    expect($admin->can('delete', $packageType))->toBeTrue();

    Livewire::actingAs($admin)
        ->test(EditPackageType::class, ['record' => $packageType->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified();

    expect($packageType->fresh()->trashed())->toBeTrue();
    expect(PackageType::withTrashed()->find($packageType->id))->not->toBeNull();

    Livewire::actingAs($admin)
        ->test(ListPackageTypes::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$packageType->fresh()]);

    Livewire::actingAs($admin)
        ->test(EditPackageType::class, ['record' => $packageType->getKey()])
        ->callAction(RestoreAction::class)
        ->assertNotified();

    expect($packageType->fresh()->trashed())->toBeFalse();
});

it('never allows force deleting a package type', function (): void {
    $admin = createPackageManager();
    $packageType = PackageType::factory()->create();

    expect($admin->can('forceDelete', $packageType))->toBeFalse();

    Livewire::actingAs($admin)
        ->test(EditPackageType::class, ['record' => $packageType->getKey()])
        ->assertActionHidden(ForceDeleteAction::class);
});

it('denies package type access without the package view permission', function (): void {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get(PackageTypeResource::getUrl('index'))->assertForbidden();
});

it('grants package type list access with the package view permission', function (): void {
    $admin = createPackageManager();

    $this->actingAs($admin)->get(PackageTypeResource::getUrl('index'))->assertOk();
});

it('denies every package type bulk mutation', function (): void {
    $admin = createPackageManager();
    PackageType::factory()->create();

    expect(PackageTypeResource::canDeleteAny())->toBeFalse()
        ->and(PackageTypeResource::canForceDeleteAny())->toBeFalse()
        ->and(PackageTypeResource::canRestoreAny())->toBeFalse();

    $component = Livewire::actingAs($admin)
        ->test(ListPackageTypes::class);

    expect($component->instance()->getTable()->getBulkActions())->toBeEmpty();
});

it('filters package types by active status', function (): void {
    $admin = createPackageManager();
    $active = PackageType::factory()->create(['is_active' => true]);
    $inactive = PackageType::factory()->create(['is_active' => false]);

    Livewire::actingAs($admin)
        ->test(ListPackageTypes::class)
        ->filterTable('is_active', true)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive]);
});

it('reaches a soft-deleted package type through the edit route binding', function (): void {
    $admin = createPackageManager();
    $trashedType = PackageType::factory()->create();
    $trashedType->delete();

    $this->actingAs($admin)
        ->get(PackageTypeResource::getUrl('edit', ['record' => $trashedType]))
        ->assertOk();
});
