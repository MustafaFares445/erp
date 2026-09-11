<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\ReplenishmentProjection;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\ReplenishmentCoverageSourceType;
use App\Enums\ReplenishmentCoverageStatus;
use App\Enums\ReplenishmentRequirementStatus;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ReplenishmentCoverage;
use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Database\Eloquent\Builder;

final class ReplenishmentProjectionService
{
    public function project(WarehouseReplenishmentPolicy $policy): ReplenishmentProjection
    {
        return new ReplenishmentProjection(
            saleableAvailable: $this->saleableAvailable($policy),
            incomingInternalTransfers: $this->incomingInternalTransfers($policy),
            incomingPurchase: $this->activeCoverage($policy, ReplenishmentCoverageSourceType::PurchaseOrderLine),
            incomingSupplierReplacement: $this->activeCoverage($policy, ReplenishmentCoverageSourceType::SupplierReplacement),
            uncoveredCommittedDemand: 0.0,
        );
    }

    public function saleableAvailable(WarehouseReplenishmentPolicy $policy): float
    {
        $stock = InventoryStock::query()
            ->where('warehouse_id', $policy->warehouse_id)
            ->where('product_variant_id', $policy->product_variant_id)
            ->first();

        return $stock instanceof InventoryStock
            ? round($stock->saleableAvailableQuantity(), 6)
            : 0.0;
    }

    private function incomingInternalTransfers(WarehouseReplenishmentPolicy $policy): float
    {
        $lines = InventoryOperationLine::query()
            ->where('product_variant_id', $policy->product_variant_id)
            ->whereHas('operation', fn (Builder $query): Builder => $query
                ->where('operation_type', OperationType::InternalTransfer->value)
                ->where('destination_warehouse_id', $policy->warehouse_id)
                ->whereIn('stage', [
                    OperationStage::Ready->value,
                    OperationStage::InTransit->value,
                    OperationStage::PartiallyReceived->value,
                ]))
            ->with('operation:id,stage')
            ->get();

        $incoming = $lines->sum(function (InventoryOperationLine $line): float {
            $operation = $line->operation;

            if ($operation === null) {
                return 0.0;
            }

            if ($operation->stage === OperationStage::Ready) {
                return max(0.0, (float) $line->base_quantity);
            }

            return max(
                0.0,
                (float) $line->dispatched_base_quantity - (float) $line->received_base_quantity,
            );
        });

        return round((float) $incoming, 6);
    }

    private function activeCoverage(
        WarehouseReplenishmentPolicy $policy,
        ReplenishmentCoverageSourceType $sourceType,
    ): float {
        $quantity = ReplenishmentCoverage::query()
            ->where('source_type', $sourceType->value)
            ->where('status', ReplenishmentCoverageStatus::Active->value)
            ->whereHas('requirement', fn (Builder $query): Builder => $query
                ->where('warehouse_replenishment_policy_id', $policy->getKey())
                ->whereIn('status', [
                    ReplenishmentRequirementStatus::Open->value,
                    ReplenishmentRequirementStatus::PartiallyCovered->value,
                    ReplenishmentRequirementStatus::Covered->value,
                ]))
            ->sum('covered_base_quantity');

        return round((float) $quantity, 6);
    }
}
