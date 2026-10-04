<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Support\TicketWorkspaceState;
use App\Models\Ticket;

final readonly class TicketWorkspaceStateResolver
{
    public function __construct(
        private TicketStageResolver $stageResolver,
        private TicketBlockerResolver $blockerResolver,
        private TicketNextActionResolver $nextActionResolver,
    ) {}

    public function resolve(Ticket $ticket): TicketWorkspaceState
    {
        return new TicketWorkspaceState(
            stage: $this->stageResolver->resolve($ticket),
            blocker: $this->blockerResolver->resolve($ticket),
            nextAction: $this->nextActionResolver->resolve($ticket),
        );
    }
}
