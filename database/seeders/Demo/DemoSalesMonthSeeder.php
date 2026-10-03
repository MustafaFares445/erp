<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\Artisan;

/**
 * Sales and receivables for the demo month: quotations, orders, deliveries, invoices,
 * collections, credit notes, refunds and write-offs, all produced through the domain services
 * and replayed on a single chronological timeline (4 Sep - 2 Oct 2026).
 *
 * Runs after the opening stock (and purchasing receipts) exist; every delivery is satisfiable
 * from the opening stock alone. Rerunning is a no-op once the first quotation is present.
 */
final class DemoSalesMonthSeeder extends DemoSeeder
{
    protected function seed(DemoContext $context): void
    {
        if (DemoSalesQuotationScenes::exists()) {
            $this->note('Sales month already present - skipped.');

            return;
        }

        $kit = DemoSalesKit::make($context);
        $timeline = new DemoSalesTimeline;

        (new DemoSalesQuotationScenes($kit, $timeline))->register();
        (new DemoSalesFulfilmentScenes($kit, $timeline))->register();
        (new DemoSalesInvoiceScenes($kit, $timeline))->register();
        (new DemoSalesCollectionScenes($kit, $timeline))->register();
        (new DemoSalesCreditScenes($kit, $timeline))->register();

        $timeline->run($context, fn (string $when, string $label) => $this->note("{$when} {$label}"));

        // Deliveries do not sync stock alerts; reconcile once so sold-out variants show as out of stock.
        $context->at('2026-10-03 17:30');
        $context->as('operations');
        Artisan::call('inventory:alerts:reconcile');
    }
}
