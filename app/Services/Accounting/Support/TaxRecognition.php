<?php

declare(strict_types=1);

namespace App\Services\Accounting\Support;

use Carbon\CarbonInterface;

final readonly class TaxRecognition
{
    public function __construct(
        public CarbonInterface $date,
        public string $direction,
        public string $type,
        public int|float|string $amount,
    ) {}
}
