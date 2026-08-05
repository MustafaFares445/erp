<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::query()->firstOrCreate([
            'email' => 'admin@ierp.com',
        ], [
            'name' => 'Admin User',
            'password' => Hash::make('password'),
            'user_type' => UserType::Admin,
        ]);

        $this->call([
            InventoryPermissionSeeder::class,
            CrmPermissionSeeder::class,
            EmployeePermissionSeeder::class,
            PackageTypeSeeder::class,
            InventoryDemoSeeder::class,
        ]);
    }
}
