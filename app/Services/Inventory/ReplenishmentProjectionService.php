<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\ReplenishmentProjection;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\PurchaseOrderLine;
use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Database\Eloquent\Builder;

final readonly class ReplenishmentProjectionService
{
    public function project(WarehouseReplenishmentPolicy $policy): ReplenishmentProjection
    {
        return new ReplenishmentProjection(
            saleableAvailable: $this->saleableAvailable($policy),
            incomingInternalTransfers: $this->incomingInternalTransfers($policy),
            incomingPurchase: $this->incomingPurchase($policy),
            incomingSupplierReplacement: 0.0,
            uncoveredCommittedDemand: 0.0,
        );
    }

    public function saleableAvailable(WarehouseReplenishmentPolicy $policy): float
    {
        $stock = InventoryStock::query()
            ->where('warehouse_id', $policy->warehouse_id)
            ->where('product_variant_id', $policy->product_variant_id)
            ->first();

        return $stock instanceof InventoryStock ? $stock->saleableAvailableQuantity() : 0.0;
    }

    private function incomingInternalTransfers(WarehouseReplenishmentPolicy $policy): float
    {
        $quantity = InventoryOperationLine::query()
            ->where('product_variant_id', $policy->product_variant_id)
            ->whereHas('operation', fn (Builder $query): Builder => $query
                ->where('operation_type', OperationType::InternalTransfer->value)
                ->where('destination_warehouse_id', $policy->warehouse_id)
                ->whereIn('stage', [OperationStage::InTransit->value, OperationStage::PartiallyReceived->value]))
            ->selectRaw('coalesce(sum(dispatched_base_quantity - received_base_quantity), 0)')
            ->value('coalesce(sum(dispatched_base_quantity - received_base_quantity), 0)');

        return is_numeric($quantity) ? max(0.0, (float) $quantity) : 0.0;
    }

    private function incomingPurchase(WarehouseReplenishmentPolicy $policy): float
    {
        $quantity = PurchaseOrderLine::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->join('purchase_inbound_lines', 'purchase_inbound_lines.purchase_order_line_id', '=', 'purchase_order_lines.id')
            ->join('purchase_inbound_allocations', 'purchase_inbound_allocations.purchase_inbound_line_id', '=', 'purchase_inbound_lines.id')
            ->where('purchase_order_lines.product_variant_id', $policy->product_variant_id)
            ->where('purchase_inbound_allocations.warehouse_id', $policy->warehouse_id)
            ->whereIn('purchase_orders.status', [
                PurchaseOrderStatus::Accepted->value,
                PurchaseOrderStatus::PartiallyReceived->value,
            ])
            ->selectRaw('coalesce(sum(max(coalesce(purchase_order_lines.base_quantity, purchase_order_lines.quantity_ordered) - coalesce(purchase_order_lines.received_base_quantity, purchase_order_lines.quantity_received), 0)), 0)')
            ->value('coalesce(sum(max(coalesce(purchase_order_lines.base_quantity, purchase_order_lines.quantity_ordered) - coalesce(purchase_order_lines.received_base_quantity, purchase_order_lines.quantity_received), 0)), 0)');

        return is_numeric($quantity) ? max(0.0, (float) $quantity) : 0.0;
    }
}
