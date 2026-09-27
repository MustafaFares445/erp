<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\OrderStatus;
use App\Models\Order;

final class OrderNextActionResolver
{
    /**
     * @param  array<string, float>  $facts
     * @return array{owner: string, label: string, route: string|null}
     */
    public function resolve(Order $order, array $facts): array
    {
        return match ($order->status) {
            OrderStatus::Draft => ['owner' => 'Sales', 'label' => 'Confirm order', 'route' => null],
            OrderStatus::Confirmed => ['owner' => 'Sales', 'label' => 'Release to Logistics', 'route' => null],
            OrderStatus::Cancelled, OrderStatus::Closed => ['owner' => 'None', 'label' => 'No action required', 'route' => null],
            OrderStatus::Released => $this->released($facts),
        };
    }

    /**
     * @param  array<string, float>  $facts
     * @return array{owner: string, label: string, route: string|null}
     */
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

        if (($facts['fully_invoiced'] ?? 0.0) < 0.5) {
            return ($facts['draft_invoice_count'] ?? 0.0) > 0.0
                ? ['owner' => 'Accounting', 'label' => 'Issue invoice', 'route' => null]
                : ['owner' => 'Accounting', 'label' => 'Create invoice', 'route' => null];
        }

        if (($facts['financially_settled'] ?? 0.0) < 0.5) {
            return ['owner' => 'Customer/Accounting', 'label' => 'Complete payment', 'route' => null];
        }

        if (($facts['auto_close_due'] ?? 0.0) > 0.5) {
            return ['owner' => 'System', 'label' => 'Auto-close pending', 'route' => null];
        }

        return ['owner' => 'Customer', 'label' => 'Confirm receipt and complete order', 'route' => null];
    }
}
