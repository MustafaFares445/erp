<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OperationType;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Services\Inventory\InventoryAlertService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('inventory:alerts:reconcile')]
#[Description('Reconcile inventory stock, expiry, transfer, import, and device-identity alerts')]
final class ReconcileInventoryAlertsCommand extends Command
{
    public function handle(InventoryAlertService $inventoryAlertService): int
    {
        $processed = 0;

        InventoryStock::query()->chunkById(200, function (Collection $stocks) use ($inventoryAlertService, &$processed): void {
            foreach ($stocks as $stock) {
                $inventoryAlertService->syncStock($stock);
                $processed++;
            }
        });
        InventoryLot::query()->chunkById(200, function (Collection $lots) use ($inventoryAlertService, &$processed): void {
            foreach ($lots as $lot) {
                $inventoryAlertService->syncExpiry($lot);
                $processed++;
            }
        });
        InventoryOperation::query()
            ->where('operation_type', OperationType::InternalTransfer->value)
            ->chunkById(200, function (Collection $transfers) use ($inventoryAlertService, &$processed): void {
                foreach ($transfers as $transfer) {
                    $inventoryAlertService->syncTransferDiscrepancy($transfer);
                    $processed++;
                }
            });
        InventoryImportRun::query()->chunkById(200, function (Collection $runs) use ($inventoryAlertService, &$processed): void {
            foreach ($runs as $run) {
                $inventoryAlertService->syncImport($run);
                $processed++;
            }
        });

        $this->components->info(sprintf('Reconciled %d inventory records.', $processed));

        return self::SUCCESS;
    }
}
