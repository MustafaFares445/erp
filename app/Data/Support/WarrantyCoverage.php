<?php

declare(strict_types=1);

namespace App\Data\Support;

use App\Enums\WarrantyStatus;
use Carbon\CarbonInterface;

final readonly class WarrantyCoverage
{
    public function __construct(
        public WarrantyStatus $status,
        public ?CarbonInterface $startedOn,
        public ?CarbonInterface $expiresOn,
        public ?int $serializedInventoryUnitId,
        public string $reason,
    ) {}
}
