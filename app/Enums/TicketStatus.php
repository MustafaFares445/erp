<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Ticket;

/**
 * Lifecycle status of a {@see Ticket} (FR-020–022,
 * contracts/ticket-lifecycle.md §1). `Resolved -> InProgress` is a reopen:
 * it clears `resolved_at` and resumes the original resolution clock rather
 * than granting a fresh window (FR-025/FR-058).
 */
enum TicketStatus: string
{
    case Pending = 'pending';
    case PendingPayment = 'pending_payment';
    case Live = 'live';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case WaitingCustomer = 'waiting_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Live, self::Cancelled],
            self::PendingPayment => [self::Live, self::Cancelled],
            self::Live => [self::Assigned, self::Cancelled],
            self::Assigned => [self::InProgress, self::Live, self::Cancelled],
            self::InProgress => [self::WaitingCustomer, self::Resolved, self::Assigned, self::Cancelled],
            self::WaitingCustomer => [self::InProgress, self::Resolved, self::Cancelled],
            self::Resolved => [self::Closed, self::InProgress],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
