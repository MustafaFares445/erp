<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use DomainException;

/**
 * Thrown when an order that has left draft is asked to change (FR-025, V-06).
 *
 * The rule is checked at both checkpoints — the policy hides the action, and
 * this is what a direct service call hits. A hidden button is a courtesy; this
 * is the guarantee.
 */
final class PurchaseOrderNotEditable extends DomainException
{
    public static function status(PurchaseOrder $order): self
    {
        return new self(__('admin.purchasing.errors.not_editable', [
            'order' => $order->purchase_order_number,
            'status' => $order->status->label(),
        ]));
    }

    public static function transition(PurchaseOrder $order, PurchaseOrderStatus $target): self
    {
        return new self(__('admin.purchasing.errors.illegal_transition', [
            'order' => $order->purchase_order_number,
            'from' => $order->status->label(),
            'to' => $target->label(),
        ]));
    }
}
