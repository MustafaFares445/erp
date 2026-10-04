<?php

declare(strict_types=1);

namespace App\Data\Support;

use App\Models\SlaPolicy;
use App\Models\SupportEntitlement;

final readonly class ResolvedSlaPolicy
{
    public function __construct(
        public SlaPolicy $policy,
        public ?SupportEntitlement $entitlement,
    ) {}
}
