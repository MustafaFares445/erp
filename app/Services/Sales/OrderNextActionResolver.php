<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\OrderStatus;
use App\Models\Order;

final class OrderNextActionResolver
{
    /** @param array<string, float> $facts @return array{owner:string,label:string,route:?string} */
    public function resolve(Order $order, array $facts): array
    {
        return match ($order->status) {
            OrderStatus::Draft => ['owner' => 'Sales', 'label' => 'Confirm order', 'route' => null],
            OrderStatus::Confirmed => ['owner' => 'Sales', 'label' => 'Release to Logistics', 'route' => null],
            OrderStatus::Cancelled, OrderStatus::Closed => ['owner' => 'None', 'label' => 'No action required', 'route' => null],
            OrderStatus::Released => $this->released($facts),
        };
    }

    /** @param array<string, float> $facts @return array{owner:string,label:string,route:?string} */
    private function released(array $facts): array
    {
        if (($facts['procurement_outstanding'] ?? 0.0) > 0.000001) {
            return ['owner' => 'Purchasing', 'label' => 'Resolve supply requirement', 'route' => null];
        }

        if (($facts['remaining'] ?? 0.0) > 0.000001) {
            return ['owner' => 'Logistics', 'label' => 'Allocate remaining demand', 'route' => null];
        }

        if (($facts['ready'] ?? 0.0) > 0.000001) {
            return ['owner' => 'Logistics', 'label' => 'Dispatch goods', 'route' => null];
        }

        if (($facts['dispatched'] ?? 0.0) > ($facts['arrived'] ?? 0.0) + 0.000001) {
            return ['owner' => 'Customer/System', 'label' => 'Confirm shipment arrival', 'route' => null];
        }

        if (($facts['dispatched'] ?? 0.0) > ($facts['invoiced'] ?? 0.0) + 0.000001) {
            return ['owner' => 'Accounting', 'label' => 'Create invoice', 'route' => null];
        }

        if (($facts['outstanding_receivable'] ?? 0.0) > 0.009) {
            return ['owner' => 'Accounting', 'label' => 'Collect or record payment', 'route' => null];
        }

        return ['owner' => 'Sales', 'label' => 'Close order', 'route' => null];
    }
}
