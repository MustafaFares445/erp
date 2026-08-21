<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use Spatie\LaravelData\Data;

final class VariantPricingData extends Data
{
    public function __construct(
        public ?float $costPrice,
        public ?float $markupPercent,
        public ?float $minimumPrice,
    ) {}
}
