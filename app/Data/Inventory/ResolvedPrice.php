<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\PricingTierDiscountType;
use App\Enums\ResolvedPriceSource;
use App\Models\PricingTier;

final readonly class ResolvedPrice
{
    public function __construct(
        public float $amount,
        public ?PricingTier $pricingTier,
        public ResolvedPriceSource $source,
        public ?PricingTierDiscountType $discountType = null,
        public ?float $discountValue = null,
        ?float $baseAmount = null,
        ?float $discountAmount = null,
        public ?float $minimumPrice = null,
        public bool $isBelowFloor = false,
    ) {
        $this->baseAmount = $baseAmount ?? $amount;
        $this->discountAmount = $discountAmount ?? round($this->baseAmount - $amount, 2);
    }

    public float $baseAmount;

    public float $discountAmount;
}
