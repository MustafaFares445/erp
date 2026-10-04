<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SupportAutomationEvent;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Support\SupportAutomationEngine;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class ProcessStaleSupportTicketsCommand extends Command
{
    protected $signature = 'support:automation:stale';

    protected $description = 'Emit safe automation events for stale support tickets.';

    public function handle(SupportAutomationEngine $engine): int
    {
        if (! config('support.support_automation_enabled', false)) {
            $this->components->info('Support automation is disabled.');

            return self::SUCCESS;
        }

        Ticket::query()
            ->where('status', TicketStatus::WaitingCustomer->value)
            ->whereNotNull('waiting_customer_since')
            ->where('waiting_customer_since', '<=', now()->subDay())
            ->orderBy('id')
            ->chunkById(100, function (EloquentCollection $tickets) use ($engine): void {
                foreach ($tickets as $ticket) {
                    $bucket = now()->format('YmdH');
                    $engine->handle(
                        SupportAutomationEvent::TicketWaitingCustomerStale,
                        $ticket,
                        ['waiting_customer_since' => $ticket->waiting_customer_since?->toIso8601String()],
                        hash('sha256', 'waiting-customer-stale|'.$ticket->id.'|'.$bucket),
                    );
                }
            });

        return self::SUCCESS;
    }
}
