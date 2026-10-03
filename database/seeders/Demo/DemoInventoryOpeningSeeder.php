<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Support\Facades\Artisan;

/**
 * Opening stock, delivered as real inbound receipts from each product's primary supplier on
 * 4-7 September, followed by reorder policies at the warehouse that holds each variant.
 *
 * Policies are created only after stock exists, so the replenishment engine derives its
 * requirements from real balances rather than from an empty store.
 */
final class DemoInventoryOpeningSeeder extends DemoSeeder
{
    /** @var array<string, string> primary supplier => opening receipt date */
    private const array SupplierDay = [
        'DEMO-SUP-001' => '2026-09-04 09:30',
        'DEMO-SUP-002' => '2026-09-04 14:00',
        'DEMO-SUP-006' => '2026-09-05 10:00',
        'DEMO-SUP-003' => '2026-09-05 15:00',
        'DEMO-SUP-004' => '2026-09-07 09:30',
    ];

    /** @var array<string, string> variant suffix key => explicit lot expiry (near-expiry alert fixtures) */
    private const array ExpiryOverride = [
        'P010-A3' => '2026-10-25',
        'P011-5ML' => '2026-11-12',
        'P006-PUTTY' => '2026-11-20',
    ];

    protected function seed(DemoContext $context): void
    {
        $inventory = DemoInventory::make();

        if ($inventory->hasOpeningStock()) {
            $this->note('Opening stock already present - skipped.');

            return;
        }

        $warehouses = ['WH-MAIN' => 0, 'WH-COLD' => 1, 'WH-REPAIR' => 2];

        foreach (self::SupplierDay as $supplierCode => $moment) {
            foreach ($warehouses as $warehouseCode => $column) {
                $lines = [];

                foreach (DemoFixtures::Products as $productCode => $product) {
                    if ($product['suppliers'][0] !== $supplierCode) {
                        continue;
                    }

                    foreach ($product['variants'] as [$suffix, , , , $quantities]) {
                        if ($quantities[$column] <= 0) {
                            continue;
                        }

                        $lines[] = [
                            'variant' => $inventory->variant($productCode, $suffix),
                            'quantity' => $quantities[$column],
                            'tag' => 'OPEN',
                            'expires' => self::ExpiryOverride["{$productCode}-{$suffix}"] ?? null,
                        ];
                    }
                }

                if ($lines === []) {
                    continue;
                }

                // Cold-chain and repair stock arrives a day later than the main store.
                $day = $column === 0 ? $moment : date('Y-m-d H:i', strtotime($moment.' +'.$column.' day'));
                $context->at($day);
                $actor = $context->as('operations');

                $inventory->receive(
                    $actor,
                    $inventory->warehouse($warehouseCode),
                    $lines,
                    "DEMO-OPEN-{$supplierCode}-{$warehouseCode}",
                    $inventory->supplier($supplierCode),
                    "[DEMO] Opening stock from {$supplierCode} into {$warehouseCode}.",
                );
            }
        }

        $context->at('2026-09-07 16:00');
        $context->as('operations');
        $this->seedPolicies($inventory);

        Artisan::call('inventory:alerts:reconcile');
    }

    private function seedPolicies(DemoInventory $inventory): void
    {
        foreach (DemoFixtures::Products as $productCode => $product) {
            foreach ($product['variants'] as [$suffix, , , , $quantities, $policy]) {
                [$main, $cold, $repair] = $quantities;
                $warehouseCode = match (true) {
                    $main === 0 && $cold > 0 => 'WH-COLD',
                    $repair > $main => 'WH-REPAIR',
                    default => 'WH-MAIN',
                };

                WarehouseReplenishmentPolicy::query()->updateOrCreate(
                    [
                        'warehouse_id' => $inventory->warehouse($warehouseCode)->getKey(),
                        'product_variant_id' => $inventory->variant($productCode, $suffix)->getKey(),
                    ],
                    ['min_quantity' => (string) $policy[0], 'max_quantity' => (string) $policy[1], 'is_active' => true],
                );
            }
        }
    }
}
