<?php

declare(strict_types=1);

use App\Enums\SystemPermission;
use Database\Seeders\SystemPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('seeds the system catalogue and its role mappings on the web guard', function (): void {
    (new SystemPermissionSeeder)->run();

    expect(Permission::query()->where('guard_name', 'web')->pluck('name')->all())
        ->toContain(...SystemPermission::values())
        ->and(Role::findByName('System Admin')->permissions->pluck('name')->all())
        ->toContain(...SystemPermission::values())
        ->and(Role::findByName('Reviewer')->permissions->pluck('name')->all())
        ->toContain(SystemPermission::ConstraintView->value)
        ->not->toContain(SystemPermission::ConstraintManage->value);
});

it('can be run twice without duplicating a permission or a grant', function (): void {
    (new SystemPermissionSeeder)->run();
    (new SystemPermissionSeeder)->run();

    expect(Permission::query()->whereIn('name', SystemPermission::values())->count())
        ->toBe(count(SystemPermission::cases()))
        ->and(Role::findByName('Reviewer')->permissions)->toHaveCount(1);
});
