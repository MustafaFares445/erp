<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Bill;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Signals that the complete Accepted-PO transaction has committed.
 *
 * The Bill is included because Accounting notifications point at the exact
 * draft provisioned by the acceptance orchestrator; listeners must not need to
 * rediscover a possibly different document after commit.
 */
final class PurchaseOrderAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public PurchaseOrder $purchaseOrder,
        public Bill $bill,
    ) {}
}
