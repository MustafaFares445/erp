<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Data\Purchasing\PurchaseOrderWorkflowData;
use App\Models\PurchaseOrder;

/**
 * Request-scoped cache for read-only purchase order workflow projections.
 */
final class PurchaseOrderWorkflowProjectionStore
{
    /** @var array<string, PurchaseOrderWorkflowData> */
    private array $projections = [];

    public function __construct(private readonly PurchaseOrderWorkflowService $workflow) {}

    public function project(PurchaseOrder $order): PurchaseOrderWorkflowData
    {
        $key = ($order->getConnectionName() ?? 'default').':'.serialize($order->getKey());

        return $this->projections[$key] ??= $this->workflow->project($order);
    }
}
