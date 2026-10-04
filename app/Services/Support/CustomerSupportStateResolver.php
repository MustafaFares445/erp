<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\CustomerSupportState;
use App\Enums\CustomerSupportStage;
use App\Enums\MaintenanceStatus;
use App\Enums\TicketBlocker;
use App\Enums\TicketStatus;
use App\Models\Ticket;

final readonly class CustomerSupportStateResolver
{
    public function __construct(private TicketBlockerResolver $blockerResolver) {}

    public function resolve(Ticket $ticket): CustomerSupportState
    {
        if ($ticket->status === TicketStatus::Cancelled) {
            return new CustomerSupportState(
                CustomerSupportStage::Cancelled,
                false,
                null,
                __('This support request was cancelled.'),
                __('No further action is expected.'),
            );
        }

        if ($ticket->status === TicketStatus::Closed) {
            return new CustomerSupportState(
                CustomerSupportStage::Completed,
                false,
                null,
                __('Support work is complete.'),
                __('You can review the resolution or submit feedback.'),
            );
        }

        if ($ticket->status === TicketStatus::Resolved) {
            return new CustomerSupportState(
                CustomerSupportStage::QualityCheck,
                false,
                null,
                __('The support team has recorded a resolution.'),
                __('The case will be closed after final review.'),
            );
        }

        $blocker = $this->blockerResolver->resolve($ticket);

        if ($blocker === TicketBlocker::DiagnosticPayment) {
            return new CustomerSupportState(
                CustomerSupportStage::ActionRequired,
                true,
                'diagnostic_payment',
                __('A diagnostic payment is required before technical work can continue.'),
                __('Complete the payment to start technical work.'),
            );
        }

        if ($blocker === TicketBlocker::CustomerResponse) {
            return new CustomerSupportState(
                CustomerSupportStage::ActionRequired,
                true,
                'reply_required',
                __('The support team is waiting for your reply.'),
                __('Reply to the latest support message.'),
            );
        }

        if ($blocker === TicketBlocker::QuotationApproval || $this->hasMaintenanceAwaitingApproval($ticket)) {
            return new CustomerSupportState(
                CustomerSupportStage::ActionRequired,
                true,
                'quotation_approval',
                __('Your repair quotation is waiting for approval.'),
                __('Review and approve the repair quotation.'),
            );
        }

        if ($this->hasMaintenanceInQa($ticket)) {
            return new CustomerSupportState(
                CustomerSupportStage::QualityCheck,
                false,
                null,
                __('Repair work is in quality assurance.'),
                __('The support team will confirm completion after quality checks.'),
            );
        }

        return match ($ticket->status) {
            TicketStatus::Pending => new CustomerSupportState(
                CustomerSupportStage::Received,
                false,
                null,
                __('We received your support request.'),
                __('The support team will review the issue and equipment details.'),
            ),
            TicketStatus::Live, TicketStatus::Assigned => new CustomerSupportState(
                CustomerSupportStage::UnderReview,
                false,
                null,
                __('Your request is under review.'),
                __('A support owner will continue the technical assessment.'),
            ),
            TicketStatus::InProgress, TicketStatus::WaitingCustomer => new CustomerSupportState(
                CustomerSupportStage::InProgress,
                false,
                null,
                __('Support work is in progress.'),
                __('The support team will post the next update here.'),
            ),
            default => new CustomerSupportState(
                CustomerSupportStage::UnderReview,
                false,
                null,
                null,
                __('The support team will post the next update here.'),
            ),
        };
    }

    private function hasMaintenanceAwaitingApproval(Ticket $ticket): bool
    {
        return $ticket->maintenanceRecords()
            ->where('status', MaintenanceStatus::AwaitingApproval->value)
            ->exists();
    }

    private function hasMaintenanceInQa(Ticket $ticket): bool
    {
        return $ticket->maintenanceRecords()
            ->where('status', MaintenanceStatus::QualityAssurance->value)
            ->exists();
    }
}
