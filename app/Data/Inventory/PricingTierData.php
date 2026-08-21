<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\PricingTierVisibility;
use Spatie\LaravelData\Data;

final class PricingTierData extends Data
{
    public function __construct(
        public string $name,
        public PricingTierType $tierType,
        public PricingTierDiscountType $discountType,
        public float $discountValue,
        public ?int $customerUserId = null,
        public ?PricingTierVisibility $visibility = null,
        public ?string $validFrom = null,
        public ?string $validUntil = null,
        public bool $isActive = false,
    ) {}
}
