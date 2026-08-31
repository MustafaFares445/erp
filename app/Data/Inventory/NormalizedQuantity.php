<?php

declare(strict_types=1);

namespace App\Data\Inventory;

/**
 * An immutable UOM conversion snapshot ready to be copied onto a stock-facing document line.
 */
final readonly class NormalizedQuantity
{
    public function __construct(
        public string $transactionQuantity,
        public int $transactionUnitId,
        public string $conversionFactorSnapshot,
        public int $baseUnitId,
        public string $baseQuantity,
    ) {}
}
