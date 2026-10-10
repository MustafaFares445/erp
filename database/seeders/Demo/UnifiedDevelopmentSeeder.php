<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * ONE cohesive local development dataset. No independent demo environments/profiles.
 *
 *     php artisan migrate:fresh --seed
 *
 * DatabaseSeeder calls this automatically in the local environment.
 * Builds and retains the canonical domain-service-driven month, then enriches
 * master data and creates twelve months of CRM/quotation activity in the same DB.
 * Deliberately does not call the older disconnected legacy demo seeders.
 */
final class UnifiedDevelopmentSeeder extends DemoSeeder
{
    protected function seed(DemoContext $context): void
    {
        $this->call(DemoMonthSeeder::class);
        $this->call(UnifiedMasterDataSeeder::class);
        $this->call(UnifiedPurchasingHistorySeeder::class);
        $this->call(UnifiedSalesHistorySeeder::class);
        $this->call(UnifiedSalesFulfillmentSeeder::class);
        $this->call(UnifiedEmployeeHistorySeeder::class);
        $this->call(UnifiedSupportHistorySeeder::class);
        $this->call(UnifiedMaintenanceHistorySeeder::class);

        $this->note('Unified development company complete.');
    }
}
