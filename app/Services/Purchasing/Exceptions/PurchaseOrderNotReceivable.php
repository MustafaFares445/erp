<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use DomainException;

/**
 * Thrown when a receipt is initiated against an order that cannot accept one
 * (V-12, FR-036, FR-044).
 *
 * Only `accepted` and `partially_received` are receivable. A draft has not
 * been committed to, a pending-approval order has not yet been accepted, and
 * a terminal order is finished — none of them should be able to pull stock in.
 */
final class PurchaseOrderNotReceivable extends DomainException
{
    public static function status(PurchaseOrder $order): self
    {
        return new self(__('admin.purchasing.errors.not_receivable', [
            'order' => $order->purchase_order_number,
            'status' => $order->status->label(),
        ]));
    }

    /**
     * The destination warehouse is re-checked at receipt initiation, not only at
     * drafting: an order sent weeks ago may name a warehouse that has since been
     * deactivated.
     */
    public static function inactiveWarehouse(Warehouse $warehouse): self
    {
        return new self(__('admin.purchasing.errors.inactive_warehouse', [
            'warehouse' => $warehouse->name,
        ]));
    }
}
