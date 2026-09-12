<?php

declare(strict_types=1);

namespace App\Data\Inventory;

final readonly class ReplenishmentProjection
{
    public function __construct(
        public float $saleableAvailable,
        public float $incomingInternalTransfers,
        public float $incomingPurchase,
        public float $incomingSupplierReplacement,
        public float $uncoveredCommittedDemand,
    ) {}

    public function projectedStock(): float
    {
        return round(
            $this->saleableAvailable
            + $this->incomingInternalTransfers
            + $this->incomingPurchase
            + $this->incomingSupplierReplacement
            - $this->uncoveredCommittedDemand,
            6,
        );
    }

    public function totalConfirmedIncoming(): float
    {
        return round(
            $this->incomingInternalTransfers
            + $this->incomingPurchase
            + $this->incomingSupplierReplacement,
            6,
        );
    }
}
