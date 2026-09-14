<?php

declare(strict_types=1);

namespace App\Data\Inventory;

final readonly class LogisticsInboundLineData
{
    /**
     * @param list<LogisticsInboundAllocationData> $allocations
     * @param list<LogisticsInboundBlockerData> $blockers
     */
    public function __construct(
        public int $purchaseOrderLineId,
        public int $purchaseInboundLineId,
        public string $sku,
        public string $product,
        public string $uom,
        public string $orderedBaseQuantity,
        public string $confirmedBaseQuantity,
        public string $backorderedBaseQuantity,
        public string $allocatedBaseQuantity,
        public string $receivedBaseQuantity,
        public string $receiptInProgressBaseQuantity,
        public string $remainingBaseQuantity,
        public string $currentlyAllocatableBaseQuantity,
        public string $availableToReceiveBaseQuantity,
        public array $allocations,
        public array $blockers,
        public string $nextAction,
    ) {}
}
