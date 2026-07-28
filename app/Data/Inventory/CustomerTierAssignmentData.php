<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use Spatie\LaravelData\Data;

final class CustomerTierAssignmentData extends Data
{
    public function __construct(
        public int $customerUserId,
        public int $pricingTierId,
    ) {}
}
