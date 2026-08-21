<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use Spatie\LaravelData\Data;

final class StockDamageData extends Data
{
    public function __construct(
        public float $quantity,
        public string $reason,
        public ?int $serializedInventoryUnitId = null,
    ) {}
}
