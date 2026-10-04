<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\SlaMilestoneKey;
use App\Models\Ticket;
use App\Models\TicketSlaMilestone;

final class SlaSummaryProjector
{
    public function project(Ticket $ticket): void
    {
        $milestones = $ticket->slaMilestones()->get()->keyBy(
            static fn (TicketSlaMilestone $milestone): string => $milestone->key->value,
        );

        /** @var TicketSlaMilestone|null $response */
        $response = $milestones->get(SlaMilestoneKey::FirstResponse->value);
        /** @var TicketSlaMilestone|null $resolution */
        $resolution = $milestones->get(SlaMilestoneKey::Resolution->value);

        $attributes = [];

        if ($response instanceof TicketSlaMilestone) {
            $attributes += [
                'sla_response_target_minutes' => $response->target_minutes,
                'response_sla_started_at' => $response->started_at,
                'response_due_at' => $response->due_at,
                'response_breached' => $ticket->response_breached || $response->breached_at !== null,
            ];
        }

        if ($resolution instanceof TicketSlaMilestone) {
            $attributes += [
                'sla_resolution_target_minutes' => $resolution->target_minutes,
                'resolution_due_at' => $resolution->due_at,
                'resolution_breached' => $ticket->resolution_breached || $resolution->breached_at !== null,
                'waiting_customer_since' => $resolution->paused_at,
                'waiting_customer_accumulated_seconds' => $resolution->paused_seconds,
            ];
        }

        if ($attributes !== []) {
            $ticket->forceFill($attributes)->save();
        }
    }
}
