<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Support\SlaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * The scheduled half of contracts/ticket-lifecycle.md §6's "a
 * scheduled/on-read check" — sweeps every ticket whose clock has started
 * and is not yet closed, flagging a response/resolution breach once its due
 * time has passed (FR-054). {@see SlaService::refreshBreachFlags()} is
 * idempotent and sticky, so re-running this command never clears a flag.
 */
#[Signature('support:sla:reconcile')]
#[Description('Flag support tickets that have breached their SLA response or resolution target')]
final class ReconcileSlaBreachesCommand extends Command
{
    public function handle(SlaService $slaService): int
    {
        $processed = 0;

        Ticket::query()
            ->whereNotNull('live_at')
            ->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Cancelled])
            ->chunkById(200, function (Collection $tickets) use ($slaService, &$processed): void {
                foreach ($tickets as $ticket) {
                    $slaService->refreshBreachFlags($ticket);
                    $processed++;
                }
            });

        $this->components->info(sprintf('Reconciled %d support tickets.', $processed));

        return self::SUCCESS;
    }
}
