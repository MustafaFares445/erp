<?php

declare(strict_types=1);

namespace App\Data\Inventory;

final readonly class LogisticsInboundBlockerData
{
    public function __construct(
        public string $code,
        public string $message,
        public string $severity = 'warning',
    ) {}
}
