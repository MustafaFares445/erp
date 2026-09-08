<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Purchasing\PurchaseInboundService;

/**
 * The state of a purchase order's warehouse-allocation aggregate (Phase 0
 * remediation).
 *
 * `AwaitingAllocation` is the state {@see PurchaseInboundService::ensureForAccepted()}
 * creates the inbound in: every line exists, none has a warehouse yet.
 * `AwaitingReceipt` is reached once every line has one (`allocate()`
 * transitions it). Receiving itself is driven by `PurchaseOrderLine`'s own
 * quantity tracking, not by this status — it only needs to distinguish
 * "nothing has arrived" from "something has" for reporting.
 */
enum PurchaseInboundStatus: string
{
    case AwaitingAllocation = 'awaiting_allocation';
    case AwaitingReceipt = 'awaiting_receipt';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
