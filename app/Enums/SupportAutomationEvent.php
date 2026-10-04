<?php

declare(strict_types=1);

namespace App\Enums;

enum SupportAutomationEvent: string
{
    case TicketCreated = 'ticket.created';
    case TicketTriaged = 'ticket.triaged';
    case TicketPriorityChanged = 'ticket.priority_changed';
    case TicketStatusChanged = 'ticket.status_changed';
    case TicketMessagePosted = 'ticket.message_posted';
    case TicketAssigned = 'ticket.assigned';
    case SlaAtRisk = 'sla.at_risk';
    case SlaBreached = 'sla.breached';
    case TicketWaitingCustomerStale = 'ticket.waiting_customer_stale';
    case MaintenanceStatusChanged = 'maintenance.status_changed';
    case MaintenanceDue = 'maintenance.due';

    public function label(): string
    {
        return match ($this) {
            self::TicketCreated => __('Ticket created'),
            self::TicketTriaged => __('Ticket triaged'),
            self::TicketPriorityChanged => __('Ticket priority changed'),
            self::TicketStatusChanged => __('Ticket status changed'),
            self::TicketMessagePosted => __('Ticket message posted'),
            self::TicketAssigned => __('Ticket assigned'),
            self::SlaAtRisk => __('SLA at risk'),
            self::SlaBreached => __('SLA breached'),
            self::TicketWaitingCustomerStale => __('Waiting customer is stale'),
            self::MaintenanceStatusChanged => __('Maintenance status changed'),
            self::MaintenanceDue => __('Maintenance due'),
        };
    }
}
