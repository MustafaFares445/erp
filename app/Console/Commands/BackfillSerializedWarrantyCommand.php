<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Services\Support\WarrantyActivationService;
use Illuminate\Console\Command;

final class BackfillSerializedWarrantyCommand extends Command
{
    protected $signature = 'support:warranties:backfill {--dry-run : Report eligible confirmed shipments without changing warranty snapshots}';

    protected $description = 'Backfill serialized customer-warranty snapshots from confirmed shipment provenance only.';

    public function handle(WarrantyActivationService $activationService): int
    {
        $shipments = Shipment::query()
            ->whereNotNull('confirmed_at')
            ->with('delivery')
            ->orderBy('id')
            ->get();

        $eligible = 0;
        $activated = 0;

        foreach ($shipments as $shipment) {
            if ($shipment->delivery === null) {
                continue;
            }

            $unitCount = $shipment->delivery->movements()
                ->whereNotNull('serialized_inventory_unit_id')
                ->distinct()
                ->count('serialized_inventory_unit_id');

            if ($unitCount === 0) {
                continue;
            }

            $eligible += $unitCount;

            if (! $this->option('dry-run')) {
                $activated += $activationService->activateForShipment($shipment);
            }
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['eligible serialized movements', $eligible],
                ['activated snapshots', $activated],
                ['mode', $this->option('dry-run') ? 'dry-run' : 'apply'],
            ],
        );

        return self::SUCCESS;
    }
}
