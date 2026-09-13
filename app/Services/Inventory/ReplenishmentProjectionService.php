<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\ReplenishmentProjection;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Database\Eloquent\Builder;

final readonly class ReplenishmentProjectionService
{
    public function __construct(
        private PurchaseInboundIncomingSupplyService $purchaseIncomingSupply,
    ) {}

    public function project(WarehouseReplenishmentPolicy $policy): ReplenishmentProjection
    {
        return new ReplenishmentProjection(
            saleableAvailable: $this->saleableAvailable($policy),
            incomingInternalTransfers: $this->incomingInternalTransfers($policy),
            incomingPurchase: $this->incomingPurchase($policy),
            incomingSupplierReplacement: 0.0,
            // Saleable availability already subtracts canonical reservations.
            // No second committed-demand source exists yet, so subtracting one
            // here would double-count demand already represented by reserved stock.
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
        $incoming = $this->purchaseIncomingSupply->totalForWarehouseProduct(
            (int) $policy->warehouse_id,
            (int) $policy->product_variant_id,
        );

        return max(0.0, (float) $incoming);
    }
}
