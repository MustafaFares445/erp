<?php

declare(strict_types=1);

namespace App\Data\Inventory;

final readonly class ReplenishmentTransferSuggestion
{
    public function __construct(
        public int $sourceWarehouseId,
        public string $sourceWarehouseName,
        public float $saleableAvailable,
        public float $sourceMinimum,
        public float $sourceMaximum,
        public float $suggestedBaseQuantity,
    ) {}
}
