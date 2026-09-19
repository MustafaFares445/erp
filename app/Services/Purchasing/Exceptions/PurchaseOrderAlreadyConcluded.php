<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Models\PurchaseOrder;
use DomainException;

/**
 * Thrown when supplier-communication metadata (`sent_at`) is recorded against
 * an order whose lifecycle has already concluded.
 *
 * `sent_at` is a single audit timestamp, so re-sending a `Received`, `Closed`
 * or `Cancelled` order would overwrite the record of when it was originally
 * communicated. This is the upper bound that pairs with
 * {@see PurchaseOrderNotYetAccepted}'s lower bound: sending is legal only
 * while the order is still live, i.e. {@see PurchaseOrderStatus::isReceivable()}.
 */
final class PurchaseOrderAlreadyConcluded extends DomainException
{
    public static function status(PurchaseOrder $order): self
    {
        return new self(__('admin.purchasing.errors.already_concluded', [
            'order' => $order->purchase_order_number,
            'status' => $order->status->label(),
        ]));
    }
}
