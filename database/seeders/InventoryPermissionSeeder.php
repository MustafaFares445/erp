<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InventoryPermission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class InventoryPermissionSeeder extends Seeder
{
    /**
     * Seed the `inventory.*` permission catalogue. Idempotent: running this
     * seeder repeatedly creates each permission at most once.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (InventoryPermission::values() as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $administrator = User::query()->where('email', 'admin@ierp.com')->first();

        if ($administrator instanceof User) {
            $administrator->syncPermissions(InventoryPermission::values());
        }
    }
}
