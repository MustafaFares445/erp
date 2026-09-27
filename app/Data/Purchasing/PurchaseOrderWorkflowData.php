<?php

declare(strict_types=1);

namespace App\Data\Purchasing;

final readonly class PurchaseOrderWorkflowData
{
    public function __construct(
        public string $businessState,
        public string $supplierState,
        public string $logisticsState,
        public string $financialState,
        public string $orderedBaseQuantity,
        public string $confirmedBaseQuantity,
        public string $backorderedBaseQuantity,
        public string $unavailableBaseQuantity,
        public string $allocatedBaseQuantity,
        public string $receiptInProgressBaseQuantity,
        public string $receivedBaseQuantity,
        public string $remainingConfirmedBaseQuantity,
        public string $billTotal,
        public string $paidTotal,
        public string $outstandingTotal,
        public ?string $blocker,
        public string $nextOwner,
        public string $nextAction,
    ) {}
}
