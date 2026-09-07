<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\ConditionChangeReason;
use App\Enums\QuarantineDisposition;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class QuarantineDispositionData extends Data
{
    public function __construct(
        public int $productVariantId,
        public int $warehouseId,
        public ?int $inventoryLotId,
        public ?int $serializedInventoryUnitId,
        public string $baseQuantity,
        public QuarantineDisposition $disposition,
        public ConditionChangeReason $reasonCategory,
        public string $reason,
        public ?int $inspectedBy = null,
        public ?CarbonImmutable $inspectedAt = null,
    ) {}
}
