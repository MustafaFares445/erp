<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SystemPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants the `system.*` catalogue, following the module permission seeders.
 *
 * System Admin receives the whole catalogue rather than an enumerated list, so
 * a permission added later is never silently withheld. Reviewer receives view
 * only: auditing which limits the business has set is part of reviewing a
 * price or an approval, but moving one is not.
 */
final class SystemPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SystemPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->rolePermissions() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permissions);
        }
    }

    /** @return array<string, list<string>> */
    private function rolePermissions(): array
    {
        return [
            'System Admin' => SystemPermission::values(),
            'Reviewer' => [SystemPermission::ConstraintView->value],
        ];
    }
}
