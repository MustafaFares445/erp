<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Models\PricingTier;

final readonly class ResolvedPrice
{
    public function __construct(
        public float $amount,
        public ?PricingTier $pricingTier,
    ) {}
}
