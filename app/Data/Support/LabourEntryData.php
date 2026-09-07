<?php

declare(strict_types=1);

namespace App\Data\Support;

final readonly class LabourEntryData
{
    public function __construct(
        public int $maintenanceRecordId,
        public ?int $serviceRecordId,
        public int $employeeId,
        public string $performedOn,
        public int $minutes,
        public ?int $hourlyRateMinor = null,
        public ?string $notes = null,
    ) {}
}
