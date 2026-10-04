<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\TicketBlocker;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

final class TicketBlockerResolver
{
    public function resolve(Ticket $ticket): TicketBlocker
    {
        if ($ticket->status === TicketStatus::Cancelled) {
            return TicketBlocker::Cancelled;
        }

        if ($ticket->status === TicketStatus::Pending) {
            return TicketBlocker::TriageRequired;
        }

        if ($ticket->status === TicketStatus::PendingPayment) {
            return TicketBlocker::DiagnosticPayment;
        }

        if ($ticket->status === TicketStatus::Live && $ticket->assigned_employee_id === null) {
            return TicketBlocker::Assignment;
        }

        if ($ticket->status === TicketStatus::WaitingCustomer) {
            return TicketBlocker::CustomerResponse;
        }

        if ($ticket->isResponseBreached() || $ticket->isResolutionBreached()) {
            return TicketBlocker::SlaBreach;
        }

        if ($this->hasPendingMaintenanceQuotation($ticket)) {
            return TicketBlocker::QuotationApproval;
        }

        if ($this->needsMaintenanceRequest($ticket)) {
            return TicketBlocker::MaintenanceAction;
        }

        return TicketBlocker::None;
    }

    private function hasPendingMaintenanceQuotation(Ticket $ticket): bool
    {
        $projected = $ticket->getAttribute('has_maintenance_quotation_pending');

        if ($projected !== null) {
            return (bool) $projected;
        }

        return $ticket->maintenanceRecords()
            ->where('status', 'awaiting_approval')
            ->whereHas('quotation', static fn (Builder $query) => $query->whereIn('status', ['sent', 'changes_requested']))
            ->exists();
    }

    private function needsMaintenanceRequest(Ticket $ticket): bool
    {
        if (! in_array($ticket->status, [TicketStatus::Live, TicketStatus::Assigned, TicketStatus::InProgress], true)
            || ! in_array($ticket->service_path, [TicketServicePath::Maintenance, TicketServicePath::OnSiteVisit], true)) {
            return false;
        }

        $projected = $ticket->getAttribute('has_active_maintenance');

        if ($projected !== null) {
            return ! (bool) $projected;
        }

        return ! $ticket->maintenanceRecords()
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->exists();
    }
}
