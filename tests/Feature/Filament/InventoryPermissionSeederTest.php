<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('seeds the full inventory permission catalogue on the web guard', function (): void {
    (new InventoryPermissionSeeder)->run();

    $names = Permission::query()->where('guard_name', 'web')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(InventoryPermission::values());
});

it('does not create duplicate permissions when the seeder runs twice', function (): void {
    (new InventoryPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();

    expect(Permission::query()->count())->toBe(count(InventoryPermission::values()));
});

it('grants the full inventory catalogue to the seeded administrator', function (): void {
    $administrator = User::factory()->create(['email' => 'admin@ierp.com']);

    (new InventoryPermissionSeeder)->run();

    expect($administrator->fresh()->getAllPermissions()->pluck('name')->all())
        ->toEqualCanonicalizing(InventoryPermission::values());
});

it('grants a user exactly the permission subset assigned to their role', function (): void {
    (new InventoryPermissionSeeder)->run();

    $role = Role::create(['name' => 'warehouse-operator', 'guard_name' => 'web']);
    $role->givePermissionTo([
        InventoryPermission::WarehouseView->value,
        InventoryPermission::StockView->value,
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can(InventoryPermission::WarehouseView->value))->toBeTrue()
        ->and($user->can(InventoryPermission::StockView->value))->toBeTrue()
        ->and($user->can(InventoryPermission::AdjustmentConfirm->value))->toBeFalse()
        ->and($user->can(InventoryPermission::Export->value))->toBeFalse();
});
