<?php

declare(strict_types=1);

namespace App\Data\Sales;

final readonly class OrderFulfillmentLineProgress
{
    public function __construct(
        public int $orderLineId,
        public int $productVariantId,
        public float $orderedBase,
        public float $shortClosedBase,
        public float $plannedBase,
        public float $reservedBase,
        public float $readyBase,
        public float $dispatchedBase,
        public float $arrivedBase,
        public float $returnedBase,
        public float $invoicedBase,
        public float $remainingToPlanBase,
    ) {}
}
