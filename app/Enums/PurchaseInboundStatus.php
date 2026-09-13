<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Purchasing\PurchaseInboundStatusService;

/**
 * Aggregate lifecycle of the physical inbound side of a purchase order.
 *
 * The status is derived by {@see PurchaseInboundStatusService} from exact
 * allocation quantities and completed Inventory receipts:
 * - awaiting_allocation: at least one inbound line is not fully allocated and
 *   no physical receipt has completed yet;
 * - awaiting_receipt: every inbound line is fully allocated, with no completed
 *   receipt yet;
 * - partially_received: at least one completed receipt exists but the inbound
 *   is not fully received;
 * - received: every inbound line has been physically received in full;
 * - cancelled: terminal and never recalculated by the aggregate status engine.
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
