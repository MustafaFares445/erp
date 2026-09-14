<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OperationType;
use App\Enums\ShipmentStatus;
use App\Events\InventoryOperationCompleted;
use App\Models\Shipment;

final class MarkShipmentInTransitOnDeliveryCompleted
{
    public function handle(InventoryOperationCompleted $event): void
    {
        $operation = $event->operation;
        if ($operation->operation_type !== OperationType::Delivery || ! $operation->isDone()) {
            return;
        }

        $shipment = Shipment::query()
            ->where('inventory_operation_id', $operation->getKey())
            ->lockForUpdate()
            ->first();

        if ($shipment instanceof Shipment && $shipment->status === ShipmentStatus::Planned) {
            $shipment->markInTransit();
        }
    }
}
