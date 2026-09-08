<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Models\PurchaseOrder;
use DomainException;

/**
 * Thrown when supplier-communication metadata (`sent_at`) is recorded against
 * an order that has not yet been accepted.
 *
 * `sent_at` is audit metadata layered on top of `Accepted` (Phase 0
 * remediation) — it is never itself a lifecycle state, so this is a distinct
 * failure from {@see PurchaseOrderNotEditable}, which is about the order's own
 * fields and lines, not about recording that it was communicated.
 */
final class PurchaseOrderNotYetAccepted extends DomainException
{
    public static function status(PurchaseOrder $order): self
    {
        return new self(__('admin.purchasing.errors.not_yet_accepted', [
            'order' => $order->purchase_order_number,
            'status' => $order->status->label(),
        ]));
    }
}
