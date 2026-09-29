<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SalesOrderReleased;
use App\Services\Sales\SalesProcurementRequirementService;

final readonly class SynchronizeSalesProcurementOnOrderReleased
{
    public function __construct(
        private SalesProcurementRequirementService $requirements,
    ) {}

    public function handle(SalesOrderReleased $event): void
    {
        $this->requirements->synchronize($event->order);
    }
}
