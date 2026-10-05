<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

trait SeedsPermissionCatalog
{
    /**
     * Seed an immutable permission catalogue with one insert operation.
     *
     * @param  list<string>  $permissions
     */
    protected function seedPermissionCatalog(array $permissions, string $guard = 'web'): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->insertOrIgnore(array_map(
            static fn (string $permission): array => [
                'name' => $permission,
                'guard_name' => $guard,
            ],
            array_values(array_unique($permissions)),
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
