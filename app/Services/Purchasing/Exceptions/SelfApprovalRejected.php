<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Models\PurchaseOrder;
use DomainException;

/**
 * Thrown when the user who submitted an above-threshold order tries to approve
 * it themselves (V-07, R-005, FR-022).
 *
 * Without this the threshold would be decorative: one user could submit and
 * immediately self-approve any amount. System Admin is exempt, because a
 * single-admin deployment would otherwise deadlock with nothing approvable.
 */
final class SelfApprovalRejected extends DomainException
{
    public static function for(PurchaseOrder $order): self
    {
        return new self(__('admin.purchasing.errors.self_approval', [
            'order' => $order->purchase_order_number,
        ]));
    }
}
