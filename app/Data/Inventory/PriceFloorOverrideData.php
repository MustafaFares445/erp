<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use Spatie\LaravelData\Data;

final class PriceFloorOverrideData extends Data
{
    public function __construct(
        public int $productVariantId,
        public ?int $customerUserId,
        public float $attemptedPrice,
        public string $reason,
        public ?int $pricingTierId = null,
    ) {}
}
