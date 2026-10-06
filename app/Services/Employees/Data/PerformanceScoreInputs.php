<?php

declare(strict_types=1);

namespace App\Services\Employees\Data;

use Spatie\LaravelData\Data;

final class PerformanceScoreInputs extends Data
{
    public function __construct(
        public int $totalTasks,
        public int $completedTasks,
        public int $onTimeCompletedTasks,
        public int $totalVisits,
        public int $completedVisits,
        public int $durationCompliantVisits,
        public int $visitsMissingTimestamps,
        public int $detectedOpportunities,
        public int $requiredVisitMinutes,
        public float $taskWeight,
        public float $visitWeight,
        public float $scheduleWeight,
        public float $workTimeWeight,
        public float $opportunityWeight,
    ) {}
}
