<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shipments\ShipmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('inventory:shipments:auto-arrive')]
#[Description('Automatically mark shipments as arrived after six hours')]
final class AutoArriveShipmentsCommand extends Command
{
    public function handle(ShipmentService $shipmentService): int
    {
        $arrived = 0;

        $shipmentService->eligibleForAutomaticArrival()
            ->orderBy('id')
            ->chunkById(100, function (Collection $shipments) use ($shipmentService, &$arrived): void {
                foreach ($shipments as $shipment) {
                    $shipmentService->confirmBySystem($shipment);
                    $arrived++;
                }
            });

        $this->components->info(sprintf('Automatically arrived %d shipments.', $arrived));

        return self::SUCCESS;
    }
}
