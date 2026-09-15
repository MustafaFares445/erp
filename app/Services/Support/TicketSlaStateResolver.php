<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Carbon\CarbonInterface;

/**
 * Presentation-oriented interpretation of the ticket SLA snapshot. The SLA
 * engine remains authoritative for deadlines and sticky breach flags; this
 * class only turns those fields into one operational state for Filament.
 */
final readonly class TicketSlaStateResolver
{
    public function label(Ticket $ticket): string
    {
        if ($ticket->live_at === null) {
            return 'Not Started';
        }

        if ($ticket->status === TicketStatus::WaitingCustomer) {
            return 'Paused — Customer';
        }

        if ($ticket->isResponseBreached()) {
            return 'Response Breached';
        }

        if ($ticket->isResolutionBreached()) {
            return 'Resolution Breached';
        }

        if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            return 'Completed';
        }

        if ($this->isAtRisk($ticket)) {
            return 'At Risk';
        }

        return 'On Track';
    }

    public function color(Ticket $ticket): string
    {
        return match ($this->label($ticket)) {
            'Response Breached', 'Resolution Breached' => 'danger',
            'At Risk' => 'warning',
            'Paused — Customer' => 'info',
            'Completed' => 'success',
            'Not Started' => 'gray',
            default => 'success',
        };
    }

    private function isAtRisk(Ticket $ticket): bool
    {
        if ($ticket->first_response_at === null
            && $ticket->response_due_at instanceof CarbonInterface
            && is_numeric($ticket->sla_response_target_minutes)) {
            return $this->deadlineAtRisk(
                $ticket->response_due_at,
                (int) $ticket->sla_response_target_minutes,
            );
        }

        if ($ticket->resolved_at === null
            && $ticket->resolution_due_at instanceof CarbonInterface
            && is_numeric($ticket->sla_resolution_target_minutes)) {
            return $this->deadlineAtRisk(
                $ticket->resolution_due_at,
                (int) $ticket->sla_resolution_target_minutes,
            );
        }

        return false;
    }

    private function deadlineAtRisk(CarbonInterface $deadline, int $targetMinutes): bool
    {
        if ($targetMinutes <= 0 || $deadline->isPast()) {
            return false;
        }

        $remainingMinutes = now()->diffInMinutes($deadline, false);

        return $remainingMinutes >= 0
            && $remainingMinutes <= max(1, (int) ceil($targetMinutes * 0.25));
    }
}
