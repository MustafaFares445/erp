<?php

declare(strict_types=1);

namespace App\Services\Employees\Data;

use App\Services\Employees\PerformanceScoringService;
use Spatie\LaravelData\Data;

/**
 * Output of {@see PerformanceScoringService::calculate()}.
 * `totalScore` is what drives pay (D2); `taskCompletionPercent` is a
 * displayed statistic only (FR-063).
 */
final class PerformanceScoreResult extends Data
{
    /**
     * @param  array<string, mixed>  $breakdown
     */
    public function __construct(
        public float $taskScore,
        public float $visitScore,
        public float $scheduleScore,
        public float $workTimeScore,
        public float $totalScore,
        public float $taskCompletionPercent,
        public array $breakdown,
    ) {}
}
