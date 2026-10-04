<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Support\Facades\Artisan;

/**
 * The warehouse and purchasing leads' end-of-month review of reorder policies (2 October).
 *
 * Month-end sales and transfers left many reorder levels breached. Levels that were set
 * too tight for real demand are lowered, policies for stock that moved to the repair bench
 * are re-pointed there, and a discontinued item is switched off. The remaining breaches are
 * the genuine open needs the purchasing dashboard should show.
 */
final class DemoReplenishmentReviewSeeder extends DemoSeeder
{
    /**
     * product, suffix, warehouse, action: new minimum, or 'off' to deactivate.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int|string}>
     */
    private const array Review = [
        ['P003', '250ML', 'WH-MAIN', 0],
        ['P005', '45MM', 'WH-MAIN', 15],
        ['P007', 'M', 'WH-MAIN', 20],
        ['P012', '21MM', 'WH-MAIN', 5],
        ['P015', '20X30', 'WH-COLD', 3],
        ['P016', 'BASIC', 'WH-MAIN', 'off'],
        ['P016', 'PREMIUM', 'WH-MAIN', 'off'],
        ['P017', 'STD', 'WH-MAIN', 5],
        ['P019', 'HANDPIECE', 'WH-MAIN', 0],
        ['P019', 'MOTOR', 'WH-MAIN', 0],
    ];

    protected function seed(DemoContext $context): void
    {
        $inventory = DemoInventory::make();
        $context->at('2026-10-02 15:00');
        $context->as('operations');

        foreach (self::Review as [$product, $suffix, $warehouse, $action]) {
            $policy = WarehouseReplenishmentPolicy::query()
                ->where('warehouse_id', $inventory->warehouse($warehouse)->getKey())
                ->where('product_variant_id', $inventory->variant($product, $suffix)->getKey())
                ->first();
            if (! $policy instanceof WarehouseReplenishmentPolicy) {
                continue;
            }
            if (! $policy->is_active) {
                continue;
            }

            $action === 'off'
                ? $policy->update(['is_active' => false])
                : $policy->update(['min_quantity' => (string) $action]);
        }

        // Burs now live at the bench, so the policy follows the stock.
        WarehouseReplenishmentPolicy::query()->updateOrCreate(
            [
                'warehouse_id' => $inventory->warehouse('WH-REPAIR')->getKey(),
                'product_variant_id' => $inventory->variant('P016', 'BASIC')->getKey(),
            ],
            ['min_quantity' => '4', 'max_quantity' => '20', 'is_active' => true],
        );

        $context->at('2026-10-03 07:30');
        Artisan::call('inventory:alerts:reconcile');
    }
}
