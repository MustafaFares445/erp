<?php

declare(strict_types=1);

namespace App\Services\Employees\Data;

use App\Services\Employees\PerformanceScoringService;
use Spatie\LaravelData\Data;

/**
 * Plain scalar inputs to {@see PerformanceScoringService::calculate()}
 * (contracts/performance-scoring.md). Gathering these from the database is a
 * separate concern from the pure arithmetic, which is what keeps the scoring
 * math itself unit-testable without a database connection.
 */
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
        public int $requiredVisitMinutes,
        public float $taskWeight,
        public float $visitWeight,
        public float $scheduleWeight,
        public float $workTimeWeight,
    ) {}
}
