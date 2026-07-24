<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use Spatie\LaravelData\Data;

final class PricingTierData extends Data
{
    public function __construct(
        public string $name,
        public float $discountPercent,
        public ?int $customerUserId,
        public bool $isActive,
    ) {}
}
