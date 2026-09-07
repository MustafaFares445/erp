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
        $this->tierId = $pricingTier?->getKey() === null ? null : (int) $pricingTier->getKey();
        $this->listPriceMinor = self::toMinor($this->baseAmount);
        $this->floorPriceMinor = $minimumPrice === null ? null : self::toMinor($minimumPrice);
    }

    public float $baseAmount;

    public float $discountAmount;

    public ?int $tierId;

    public int $listPriceMinor;

    public ?int $floorPriceMinor;

    private static function toMinor(float $amount): int
    {
        return max(0, (int) round($amount * 100));
    }
}
