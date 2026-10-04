<?php

declare(strict_types=1);

namespace App\Data\Support;

use App\Enums\TicketBlocker;
use App\Enums\TicketStage;

final readonly class TicketWorkspaceState
{
    public function __construct(
        public TicketStage $stage,
        public TicketBlocker $blocker,
        public string $nextAction,
    ) {}
}
