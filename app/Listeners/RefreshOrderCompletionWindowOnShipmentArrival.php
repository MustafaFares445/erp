<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ShipmentArrived;
use App\Models\Order;
use App\Services\Sales\OrderCompletionService;

final readonly class RefreshOrderCompletionWindowOnShipmentArrival
{
    public function __construct(private OrderCompletionService $completionService) {}

    public function handle(ShipmentArrived $event): void
    {
        $order = $event->shipment->order;

        if ($order instanceof Order) {
            $this->completionService->refreshCompletionWindow($order);
        }
    }
}
