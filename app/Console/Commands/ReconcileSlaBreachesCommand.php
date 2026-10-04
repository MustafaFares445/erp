<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SupportAutomationEvent;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Support\SlaService;
use App\Services\Support\SupportAutomationEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Scheduled support-SLA reconciliation.
 *
 * Besides sticky breach flags, this command emits deterministic at-risk
 * automation events before the deadlines so escalation rules can react
 * without creating duplicate runs on each five-minute sweep.
 */
#[Signature('support:sla:reconcile')]
#[Description('Reconcile support SLA risk and breach state')]
final class ReconcileSlaBreachesCommand extends Command
{
    public function handle(SlaService $slaService, SupportAutomationEngine $automation): int
    {
        $processed = 0;

        Ticket::query()
            ->whereNotNull('live_at')
            ->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Cancelled])
            ->chunkById(200, function (Collection $tickets) use ($slaService, $automation, &$processed): void {
                foreach ($tickets as $ticket) {
                    $this->emitAtRiskEvents($ticket, $automation);
                    $slaService->refreshBreachFlags($ticket);
                    $processed++;
                }
            });

        $this->components->info(sprintf('Reconciled %d support tickets.', $processed));

        return self::SUCCESS;
    }

    private function emitAtRiskEvents(Ticket $ticket, SupportAutomationEngine $automation): void
    {
        if (! config('support.support_automation_enabled', false)) {
            return;
        }

        if ($ticket->first_response_at === null
            && $ticket->response_due_at !== null
            && now()->lte($ticket->response_due_at)
            && $ticket->response_due_at->lte(now()->addHour())) {
            $automation->handle(
                SupportAutomationEvent::SlaAtRisk,
                $ticket,
                ['milestone' => 'first_response', 'due_at' => $ticket->response_due_at->toIso8601String()],
                hash('sha256', 'sla-risk|'.$ticket->id.'|first_response|'.$ticket->response_due_at->timestamp),
            );
        }

        if ($ticket->resolved_at === null
            && $ticket->resolution_due_at !== null
            && now()->lte($ticket->resolution_due_at)
            && $ticket->resolution_due_at->lte(now()->addHours(4))) {
            $automation->handle(
                SupportAutomationEvent::SlaAtRisk,
                $ticket,
                ['milestone' => 'resolution', 'due_at' => $ticket->resolution_due_at->toIso8601String()],
                hash('sha256', 'sla-risk|'.$ticket->id.'|resolution|'.$ticket->resolution_due_at->timestamp),
            );
        }
    }
}
