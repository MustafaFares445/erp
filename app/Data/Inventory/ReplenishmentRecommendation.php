<?php

declare(strict_types=1);

namespace App\Data\Inventory;

final readonly class ReplenishmentRecommendation
{
    public function __construct(
        public float $available,
        public float $reserved,
        public float $incoming,
        public float $minimum,
        public float $maximum,
        public float $projected,
        public float $suggestedBaseQuantity,
        public ?int $supplierProductReferenceId,
        public ?int $supplierId,
        public ?string $supplierName,
        public ?int $leadTimeDays,
        public ?string $currencyCode,
    ) {}

    public function needsPurchase(): bool
    {
        return $this->suggestedBaseQuantity > 0.000001;
    }
}
