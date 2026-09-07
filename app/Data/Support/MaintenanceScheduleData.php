<?php

declare(strict_types=1);

namespace App\Data\Support;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;

final readonly class MaintenanceScheduleData
{
    /**
     * @param  array<int, mixed>|null  $checklist
     */
    public function __construct(
        public int $serializedInventoryUnitId,
        public ?int $customerId,
        public string $name,
        public MaintenanceIntervalType $intervalType,
        public int $intervalValue,
        public int $leadTimeDays,
        public string $firstDueOn,
        public MaintenanceBillingType $billingType,
        public ?array $checklist = null,
    ) {}
}
