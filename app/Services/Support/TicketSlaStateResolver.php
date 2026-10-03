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
        return (string) __('admin.support.sla_state.'.$this->state($ticket));
    }

    public function color(Ticket $ticket): string
    {
        return match ($this->state($ticket)) {
            'response_breached', 'resolution_breached' => 'danger',
            'at_risk' => 'warning',
            'paused' => 'info',
            'not_started' => 'gray',
            default => 'success',
        };
    }

    /** The locale-independent SLA state key behind both the label and the colour. */
    private function state(Ticket $ticket): string
    {
        if ($ticket->live_at === null) {
            return 'not_started';
        }

        if ($ticket->status === TicketStatus::WaitingCustomer) {
            return 'paused';
        }

        if ($ticket->isResponseBreached()) {
            return 'response_breached';
        }

        if ($ticket->isResolutionBreached()) {
            return 'resolution_breached';
        }

        if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            return 'completed';
        }

        if ($this->isAtRisk($ticket)) {
            return 'at_risk';
        }

        return 'on_track';
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
