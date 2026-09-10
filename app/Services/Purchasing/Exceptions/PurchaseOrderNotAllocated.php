<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Models\PurchaseOrder;
use DomainException;

/**
 * Thrown when receiving is initiated before the order's warehouse allocation
 * is usable.
 *
 * Two distinct causes share this exception: {@see self::unallocated()} means
 * no line has a warehouse yet (the Inventory Manager has not allocated the
 * inbound), and {@see self::ambiguous()} means the outstanding lines resolve
 * to more than one warehouse, so `PurchaseOrderReceivingService` cannot pick
 * a single destination for the receipt on the caller's behalf.
 */
final class PurchaseOrderNotAllocated extends DomainException
{
    public static function unallocated(PurchaseOrder $order): self
    {
        return new self(__('admin.purchasing.errors.not_allocated', [
            'order' => $order->purchase_order_number,
        ]));
    }

    public static function ambiguous(PurchaseOrder $order): self
    {
        return new self(__('admin.purchasing.errors.ambiguous_warehouse', [
            'order' => $order->purchase_order_number,
        ]));
    }
}
