<?php

declare(strict_types=1);

namespace App\Data\Sales;

use App\Enums\OrderStatus;

final readonly class OrderWorkflowProjection
{
    public function __construct(
        public OrderStatus $commercialStatus,
        public string $businessMilestone,
        public float $fulfillmentProgressPercent,
        public float $requestedBase,
        public float $plannedBase,
        public float $readyBase,
        public float $dispatchedBase,
        public float $arrivedBase,
        public float $returnedBase,
        public float $remainingBase,
        public float $procurementOutstandingBase,
        public float $invoiceTotal,
        public float $paidTotal,
        public float $creditedTotal,
        public float $outstandingReceivable,
        public ?string $blockerCode,
        public ?string $blockerMessage,
        public string $nextActionOwner,
        public string $nextActionLabel,
        public ?string $nextActionRoute,
    ) {}
}
