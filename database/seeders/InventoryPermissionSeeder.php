<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InventoryPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

final class InventoryPermissionSeeder extends Seeder
{
    /**
     * Seed the `inventory.*` permission catalogue. Idempotent: running this
     * seeder repeatedly creates each permission at most once.
     */
    public function run(): void
    {
        foreach (InventoryPermission::values() as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
    }
}
