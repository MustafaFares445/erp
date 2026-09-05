<?php

declare(strict_types=1);

namespace App\Support;

use DomainException;

final readonly class ProportionalAllocator
{
    public function allocate(
        int $totalMinor,
        int $partMinor,
        int $wholeMinor,
        int $alreadyAllocatedMinor = 0,
        bool $settlesRemainder = false,
    ): int {
        if ($totalMinor < 0 || $partMinor < 0 || $wholeMinor <= 0 || $alreadyAllocatedMinor < 0) {
            throw new DomainException('Proportional allocation requires non-negative amounts and a positive whole.');
        }

        $remainingMinor = max(0, $totalMinor - $alreadyAllocatedMinor);

        if ($remainingMinor === 0 || $partMinor === 0) {
            return 0;
        }

        if ($settlesRemainder) {
            return $remainingMinor;
        }

        $allocatedMinor = intdiv($totalMinor * min($partMinor, $wholeMinor), $wholeMinor);

        return min($remainingMinor, max(0, $allocatedMinor));
    }
}
