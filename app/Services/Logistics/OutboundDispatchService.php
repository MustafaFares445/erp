<?php

declare(strict_types=1);

namespace App\Services\Logistics;

use App\Enums\OperationType;
use App\Enums\ShipmentStatus;
use App\Models\InventoryOperation;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Inventory\InventoryOperationService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class OutboundDispatchService
{
    public function __construct(private InventoryOperationService $inventoryOperations) {}

    /** @param array<string, mixed> $shipmentData */
    public function dispatch(User $actor, InventoryOperation $delivery, array $shipmentData = []): Shipment
    {
        Gate::forUser($actor)->authorize('complete', $delivery);

        return DB::transaction(function () use ($actor, $delivery, $shipmentData): Shipment {
            $lockedDelivery = InventoryOperation::query()
                ->whereKey($delivery->getKey())
                ->lockForUpdate()
                ->sole();

            if ($lockedDelivery->operation_type !== OperationType::Delivery) {
                throw new DomainException('Outbound dispatch is available only for customer Delivery operations.');
            }

            $shipment = Shipment::query()
                ->where('inventory_operation_id', $lockedDelivery->getKey())
                ->lockForUpdate()
                ->first();

            if (! $shipment instanceof Shipment || $shipment->status !== ShipmentStatus::Planned) {
                throw new DomainException('A planned shipment is required before dispatch.');
            }

            if (isset($shipmentData['tracking_number']) && is_string($shipmentData['tracking_number'])) {
                $shipment->tracking_number = mb_trim($shipmentData['tracking_number']);
                $shipment->save();
            }

            $completed = $this->inventoryOperations->complete($lockedDelivery, $actor);

            // @codeCoverageIgnoreStart
            // InventoryOperationService::complete() either returns a Done operation or throws.
            if (! $completed->isDone()) {
                throw new DomainException('Inventory delivery did not complete successfully.');
            }
            // @codeCoverageIgnoreEnd

            $shipment->refresh();
            if ($shipment->status === ShipmentStatus::Planned) {
                $shipment->markInTransit();
                $shipment->refresh();
            }

            // @codeCoverageIgnoreStart
            // The completion listener transitions Planned -> InTransit; if listeners are suppressed,
            // the fallback above performs the same transition. No supported path leaves another status here.
            if ($shipment->status !== ShipmentStatus::InTransit) {
                throw new DomainException('Shipment did not enter transit after inventory dispatch.');
            }
            // @codeCoverageIgnoreEnd

            activity()->performedOn($shipment)->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'logistics',
                    'inventory_operation_id' => $completed->getKey(),
                ])
                ->log('logistics.customer_delivery.dispatched');

            return $shipment;
        }, attempts: 5);
    }
}
