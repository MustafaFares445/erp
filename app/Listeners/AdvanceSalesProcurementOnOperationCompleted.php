<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OperationType;
use App\Events\InventoryOperationCompleted;
use App\Models\PurchaseOrder;
use App\Services\Sales\SalesProcurementService;

final readonly class AdvanceSalesProcurementOnOperationCompleted
{
    public function __construct(private SalesProcurementService $procurement) {}

    public function handle(InventoryOperationCompleted $event): void
    {
        $operation = $event->operation;

        if ($operation->operation_type !== OperationType::Receipt
            || $operation->source_document_type !== PurchaseOrder::class
            || $operation->source_document_id === null) {
            return;
        }

        $purchaseOrder = PurchaseOrder::query()->find($operation->source_document_id);

        if ($purchaseOrder instanceof PurchaseOrder) {
            $this->procurement->refreshFromPurchaseOrder($purchaseOrder);
        }
    }
}
