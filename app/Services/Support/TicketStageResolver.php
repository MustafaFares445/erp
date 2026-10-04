<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\TicketStage;
use App\Enums\TicketStatus;
use App\Models\Ticket;

final class TicketStageResolver
{
    public function resolve(Ticket $ticket): TicketStage
    {
        return match ($ticket->status) {
            TicketStatus::Pending => TicketStage::Intake,
            TicketStatus::PendingPayment => TicketStage::Triage,
            TicketStatus::Live, TicketStatus::Assigned, TicketStatus::InProgress, TicketStatus::WaitingCustomer => TicketStage::ActiveSupport,
            TicketStatus::Resolved => TicketStage::Resolution,
            TicketStatus::Closed, TicketStatus::Cancelled => TicketStage::Closed,
        };
    }
}
