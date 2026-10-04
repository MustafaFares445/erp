<?php

declare(strict_types=1);

namespace App\Data\Support;

final readonly class EquipmentReliabilityMetrics
{
    /** @param array<string, int> $repeatFailureCategories */
    public function __construct(
        public int $activeTickets,
        public int $activeMaintenanceJobs,
        public ?string $lastServiceAt,
        public ?string $nextPreventiveDueOn,
        public int $lifetimeServiceCostMinor,
        public int $warrantyCoveredCostMinor,
        public int $recoveryClaimedMinor,
        public int $recoveryReceivedMinor,
        public int $recoveryOutstandingMinor,
        public int $correctiveFailureCount,
        public array $repeatFailureCategories,
        public ?float $mttrMinutes,
        public ?float $mtbfHours,
    ) {}
}
