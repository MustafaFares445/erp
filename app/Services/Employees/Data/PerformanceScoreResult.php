<?php

declare(strict_types=1);

namespace App\Services\Employees\Data;

use Spatie\LaravelData\Data;

final class PerformanceScoreResult extends Data
{
    /** @param array<string, mixed> $breakdown */
    public function __construct(
        public float $taskScore,
        public float $visitScore,
        public float $scheduleScore,
        public float $workTimeScore,
        public float $opportunityScore,
        public float $totalScore,
        public float $taskCompletionPercent,
        public array $breakdown,
    ) {}
}
