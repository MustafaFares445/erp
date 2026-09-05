<?php

declare(strict_types=1);

namespace App\Data\Crm;

use App\Enums\InteractionDirection;
use App\Enums\InteractionOutcome;
use App\Enums\InteractionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final readonly class InteractionData
{
    public function __construct(
        public Model $subject,
        public InteractionType $type,
        public InteractionDirection $direction,
        public Carbon $occurredAt,
        public string $summary,
        public ?InteractionOutcome $outcome = null,
        public ?string $notes = null,
        public ?int $customerVisitId = null,
        public ?int $ticketId = null,
    ) {}
}
