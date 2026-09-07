<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\ConditionChangeReason;
use Spatie\LaravelData\Data;

final class DisposalDraftData extends Data
{
    public function __construct(
        public int $productVariantId,
        public int $warehouseId,
        public ?int $inventoryLotId,
        public ?int $serializedInventoryUnitId,
        public string $baseQuantity,
        public ConditionChangeReason $reasonCategory,
        public string $reason,
        public int $authorisedBy,
    ) {}
}
