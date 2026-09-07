<?php

declare(strict_types=1);

namespace App\Data\Support;

final readonly class ThirdPartyCostData
{
    public function __construct(
        public int $maintenanceRecordId,
        public string $description,
        public int $amountMinor,
        public string $incurredOn,
        public ?int $supplierId = null,
        public ?int $billId = null,
    ) {}
}
