<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\TicketPaymentLink;
use App\Services\Support\TicketPaymentService;

/**
 * Settlement state of a {@see TicketPaymentLink} (FR-041/043/045,
 * contracts/ticket-lifecycle.md §5). `Settled` is a one-way, idempotent
 * terminal write — {@see TicketPaymentService::settle()}
 * is the only path that ever sets it.
 */
enum PaymentLinkStatus: string
{
    case Pending = 'pending';
    case Settled = 'settled';
    case Cancelled = 'cancelled';
}
