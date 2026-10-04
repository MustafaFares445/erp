<?php

declare(strict_types=1);

namespace App\Data\Support;

use App\Models\EmployeeProfile;
use App\Models\SupportRoutingRule;
use App\Models\SupportTeam;

final readonly class RoutingDecision
{
    public function __construct(
        public SupportTeam $team,
        public ?EmployeeProfile $employee,
        public ?SupportRoutingRule $rule,
        public string $reason,
    ) {}
}
