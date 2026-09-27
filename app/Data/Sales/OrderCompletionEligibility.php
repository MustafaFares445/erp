<?php

declare(strict_types=1);

namespace App\Data\Sales;

use Carbon\CarbonImmutable;

final readonly class OrderCompletionEligibility
{
    /**
     * @param  list<array{code: string, message: string}>  $blockers
     */
    public function __construct(
        public bool $eligible,
        public bool $orderReleased,
        public bool $fulfillmentComplete,
        public bool $allShipmentsArrived,
        public bool $noOpenProcurement,
        public bool $fullyInvoiced,
        public bool $financiallySettled,
        public bool $hasCustomerEvidence,
        public ?CarbonImmutable $completionWindowStartedAt,
        public ?CarbonImmutable $autoCloseDueAt,
        public array $blockers,
    ) {}
}
