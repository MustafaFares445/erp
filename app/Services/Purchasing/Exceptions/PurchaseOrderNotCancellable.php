<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Models\PurchaseOrder;
use DomainException;

/**
 * Thrown when cancellation is attempted on an order that already has a
 * completed receipt (V-13, FR-026, R-D).
 *
 * Voiding such an order would leave stock sitting in a warehouse with no
 * commitment explaining where it came from. The message directs the buyer to
 * the short-close path, which keeps what arrived and abandons the rest.
 */
final class PurchaseOrderNotCancellable extends DomainException
{
    public static function hasCompletedReceipt(PurchaseOrder $order): self
    {
        return new self(__('admin.purchasing.errors.cancel_after_receipt', [
            'order' => $order->purchase_order_number,
        ]));
    }
}
