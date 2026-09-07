<?php

declare(strict_types=1);

namespace App\Data\Sales;

use App\Enums\OpportunityOrigin;

final readonly class OpportunityData
{
    public function __construct(
        public string $summary,
        public ?int $customerId = null,
        public ?int $leadId = null,
        public ?string $title = null,
        public ?int $estimatedValueMinor = null,
        public string $currency = 'AED',
        public ?string $expectedCloseDate = null,
        public ?int $probabilityPercent = null,
        public ?int $ownerId = null,
        public OpportunityOrigin $origin = OpportunityOrigin::Manual,
    ) {}
}
