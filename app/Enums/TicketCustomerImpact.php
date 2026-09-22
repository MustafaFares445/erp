<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Support\TicketPriorityResolver;

/**
 * How the customer describes the effect of the issue at intake — a
 * self-reported signal {@see TicketPriorityResolver}
 * combines with the ticket {@see TicketType} to propose an internal
 * {@see TicketPriority}. Distinct from priority: support can see both and
 * override the proposed priority without changing what the customer reported.
 */
enum TicketCustomerImpact: string
{
    case ServiceUnavailable = 'service_unavailable';
    case Degraded = 'degraded';
    case GeneralQuestion = 'general_question';

    public function label(): string
    {
        return __('admin.support.ticket_customer_impact.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::ServiceUnavailable => 'danger',
            self::Degraded => 'warning',
            self::GeneralQuestion => 'gray',
        };
    }
}
