<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\InventoryOperationCompleted;
use App\Services\Inventory\InventoryValuationService;

/**
 * Synchronous by design: valuation and its financial consequence commit in the
 * same transaction as the completed inventory operation.
 */
final readonly class ApplyInventoryValuationOnOperationCompleted
{
    public function __construct(private InventoryValuationService $valuation) {}

    public function handle(InventoryOperationCompleted $event): void
    {
        $this->valuation->processOperation($event->operation, $event->actor);
    }
}
