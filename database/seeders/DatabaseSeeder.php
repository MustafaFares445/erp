<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Only configuration and reference rows. Production db:seed must not create
 * fixed-password users or synthetic business transactions.
 *
 * Development data: php artisan db:seed --class="Database\Seeders\Demo\UnifiedDevelopmentSeeder"
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
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
    }
}
