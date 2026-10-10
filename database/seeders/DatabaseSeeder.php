<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Demo\UnifiedDevelopmentSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Standard Laravel bootstrap:
 *
 *     php artisan migrate:fresh --seed
 *
 * Production/testing gets reference/permission data only. Local development
 * also gets the complete, interconnected synthetic ERP demo company.
 * The destructive migrate:fresh database guard remains a separate protection.
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Refuse unsafe connections before *any* reference or demo write.
        // Existing migrate:fresh guards independently protect destructive
        // migration commands and require explicit schema opt-in.
        if (app()->environment('local')) {
            $connection = DB::getDefaultConnection();
            $host = config("database.connections.{$connection}.host");
            if (! in_array($connection, ['mysql', 'sqlite'], true)
                || ($connection === 'mysql' && (! is_string($host)
                    || ! in_array(mb_strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)))) {
                throw new RuntimeException('Refusing to seed the demo company outside a local database.');
            }
        }

        $this->call([
            CurrencySeeder::class,
            InventoryPermissionSeeder::class,
            CrmPermissionSeeder::class,
            EmployeePermissionSeeder::class,
            SupportPermissionSeeder::class,
            AccountingPermissionSeeder::class,
            PurchasePermissionSeeder::class,
            SalesPermissionSeeder::class,
            SystemPermissionSeeder::class,
            SupportServiceLevelSeeder::class,
            SlaPolicySeeder::class,
            SupportQueueSeeder::class,
            WarrantyPolicySeeder::class,
            ChartOfAccountsSeeder::class,
            NotificationTemplateSeeder::class,
            PurchaseOrderNotificationTemplateSeeder::class,
            PackageTypeSeeder::class,
        ]);

        // No custom Artisan command is required for a full local demo.
        // Production and CI receive reference data only.
        if (app()->environment('local')) {
            $this->call(UnifiedDevelopmentSeeder::class);
        }
    }
}
