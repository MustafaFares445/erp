<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OperationType;
use App\Events\InventoryOperationCanceled;
use App\Models\Shipment;

/**
 * A cancelled delivery must not leave its planned shipment live, otherwise the order can never
 * satisfy "every non-cancelled shipment has arrived". A shipment that already left (in transit or
 * arrived) is never touched: it belongs to a delivery that was dispatched, not one being cancelled.
 */
final class CancelPlannedShipmentOnDeliveryCanceled
{
    public function handle(InventoryOperationCanceled $event): void
    {
        $operation = $event->operation;

        if ($operation->operation_type !== OperationType::Delivery) {
            return;
        }

        $shipment = Shipment::query()
            ->where('inventory_operation_id', $operation->getKey())
            ->lockForUpdate()
            ->first();

        if ($shipment instanceof Shipment && $shipment->isPlanned()) {
            $shipment->markCancelled();
        }
    }
}
