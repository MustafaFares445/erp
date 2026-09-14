<?php

declare(strict_types=1);

namespace App\Data\Inventory;

final readonly class LogisticsInboundAllocationData
{
    public function __construct(
        public int $id,
        public int $warehouseId,
        public string $warehouse,
        public string $allocated,
        public string $received,
        public string $receiptInProgress,
        public string $availableToReceive,
    ) {}
}
