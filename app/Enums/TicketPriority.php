<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\SlaPolicy;

/**
 * Ticket priority (FR-050), driving the {@see SlaPolicy} lookup
 * at SLA clock-start (contracts/ticket-lifecycle.md §6).
 */
enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
