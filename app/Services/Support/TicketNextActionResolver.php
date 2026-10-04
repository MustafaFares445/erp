<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\TicketBlocker;
use App\Enums\TicketStatus;
use App\Models\Ticket;

final readonly class TicketNextActionResolver
{
    public function __construct(private TicketBlockerResolver $blockerResolver) {}

    public function resolve(Ticket $ticket): string
    {
        $blocker = $this->blockerResolver->resolve($ticket);

        return match ($blocker) {
            TicketBlocker::TriageRequired => __('Triage equipment and choose the service path'),
            TicketBlocker::DiagnosticPayment => __('Collect the diagnostic fee'),
            TicketBlocker::Assignment => __('Assign a support owner'),
            TicketBlocker::CustomerResponse => __('Follow up with the customer or wait for their reply'),
            TicketBlocker::QuotationApproval => __('Follow up on the repair quotation'),
            TicketBlocker::MaintenanceAction => __('Raise the maintenance job'),
            TicketBlocker::SlaBreach => __('Escalate this ticket and continue the current work'),
            TicketBlocker::Cancelled => __('No action — ticket cancelled'),
            TicketBlocker::None => match ($ticket->status) {
                TicketStatus::Assigned => __('Start support work'),
                TicketStatus::InProgress => __('Continue support work'),
                TicketStatus::Resolved => __('Review the resolution and close'),
                TicketStatus::Closed => __('Complete'),
                default => __('Continue support'),
            },
        };
    }
}
