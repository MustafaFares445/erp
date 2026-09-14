<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\PurchaseInboundStatus;
use Carbon\CarbonInterface;

final readonly class LogisticsInboundData
{
    /**
     * @param list<LogisticsInboundLineData> $lines
     * @param list<LogisticsInboundBlockerData> $blockers
     * @param list<string> $destinationWarehouses
     */
    public function __construct(
        public int $purchaseInboundId,
        public int $purchaseOrderId,
        public string $purchaseOrderReference,
        public string $supplier,
        public ?CarbonInterface $expectedAt,
        public PurchaseInboundStatus $inboundStatus,
        public string $businessState,
        public bool $overdue,
        public string $confirmedBaseQuantity,
        public string $allocatedBaseQuantity,
        public string $receivedBaseQuantity,
        public string $remainingBaseQuantity,
        public array $destinationWarehouses,
        public array $blockers,
        public array $lines,
        public string $nextAction,
    ) {}
}
