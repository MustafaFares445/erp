<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\EmployeePermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PackageTypeSeeder;
use Database\Seeders\PurchaseOrderNotificationTemplateSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Database\Seeders\SystemPermissionSeeder;
use Database\Seeders\WarrantyPolicySeeder;
use Illuminate\Database\Seeder;

/**
 * Orchestrates the one-month demo company (2026-09-04 .. 2026-10-03).
 *
 *     php artisan db:seed --class='Database\Seeders\Demo\DemoMonthSeeder'
 *
 * Local/demo only: every step refuses to run in production. Re-running is safe; each step
 * detects its own earlier records and skips. The order follows the business dependencies:
 * reference data, master data, opening stock, purchasing and receiving, sales through cash
 * collection, warehouse activity, CRM / employee / support operations, and finally the
 * accounting month-end that evaluates everything above.
 */
final class DemoMonthSeeder extends DemoSeeder
{
    /** @var list<class-string<Seeder>> */
    private const array SystemDefaults = [
        CurrencySeeder::class,
        InventoryPermissionSeeder::class,
        CrmPermissionSeeder::class,
        EmployeePermissionSeeder::class,
        SupportPermissionSeeder::class,
        AccountingPermissionSeeder::class,
        PurchasePermissionSeeder::class,
        SalesPermissionSeeder::class,
        SystemPermissionSeeder::class,
        SlaPolicySeeder::class,
        WarrantyPolicySeeder::class,
        ChartOfAccountsSeeder::class,
        NotificationTemplateSeeder::class,
        PurchaseOrderNotificationTemplateSeeder::class,
        PackageTypeSeeder::class,
    ];

    /** @var list<class-string<DemoSeeder>> */
    private const array Stages = [
        DemoBaselineSeeder::class,
        DemoMasterDataSeeder::class,
        DemoEmployeeRosterSeeder::class,
        DemoInventoryOpeningSeeder::class,
        DemoPurchasingMonthSeeder::class,
        DemoSalesMonthSeeder::class,
        DemoInventoryOperationsSeeder::class,
        DemoReplenishmentReviewSeeder::class,
        DemoCrmMonthSeeder::class,
        DemoEmployeeMonthSeeder::class,
        DemoSupportMonthSeeder::class,
        DemoSystemActivitySeeder::class,
        DemoAccountingMonthSeeder::class,
    ];

    protected function seed(DemoContext $context): void
    {
        $this->call(self::SystemDefaults);

        foreach (self::Stages as $stage) {
            if (! class_exists($stage)) {
                $this->note('Skipping '.class_basename($stage).' (not installed).');

                continue;
            }

            $this->note('Seeding '.class_basename($stage));
            $this->call($stage);
        }

        $this->note('Demo month complete. Run DemoVerificationSeeder for the coverage report.');
    }
}
