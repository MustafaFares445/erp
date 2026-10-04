<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Sales\OrderWorkflowProjection;
use App\Models\Order;

/**
 * Request-scoped cache for workflow projections shared by Filament tables,
 * row actions and infolists.
 */
final class OrderWorkflowProjectionStore
{
    /** @var array<string, OrderWorkflowProjection> */
    private array $projections = [];

    public function __construct(private readonly OrderWorkflowService $workflow) {}

    public function project(Order $order): OrderWorkflowProjection
    {
        $key = ($order->getConnectionName() ?? 'default').':'.serialize($order->getKey());

        return $this->projections[$key] ??= $this->workflow->project($order);
    }
}
