<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\PurchaseOrderStatus;
use App\Enums\ReplenishmentCoverageSourceType;
use App\Enums\ReplenishmentCoverageStatus;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentCoverage;
use App\Models\ReplenishmentRequirement;
use App\Models\WarehouseReplenishmentPolicy;

final readonly class PurchaseReplenishmentCoverageService
{
    public function __construct(
        private ReplenishmentCoverageService $coverages,
        private ReplenishmentRequirementService $requirements,
    ) {}

    public function syncForOrder(PurchaseOrder $order): void
    {
        if (! in_array($order->status, [PurchaseOrderStatus::Accepted, PurchaseOrderStatus::PartiallyReceived], true)) {
            $this->releaseForOrder($order);

            return;
        }

        $order->loadMissing('purchaseInbound.lines.allocation', 'purchaseInbound.lines.purchaseOrderLine');

        foreach ($order->purchaseInbound?->lines ?? [] as $line) {
            $this->syncForInboundLine($line);
        }
    }

    public function syncForInboundLine(PurchaseInboundLine $line): void
    {
        $line->loadMissing('allocation', 'purchaseOrderLine');
        $purchaseLine = $line->purchaseOrderLine;
        $allocation = $line->allocation;

        $existing = ReplenishmentCoverage::query()
            ->where('source_type', ReplenishmentCoverageSourceType::PurchaseOrderLine->value)
            ->where('source_id', $purchaseLine->getKey())
            ->where('status', ReplenishmentCoverageStatus::Active->value)
            ->get();

        if ($allocation === null) {
            foreach ($existing as $coverage) {
                $this->coverages->release($coverage);
            }

            return;
        }

        $requirement = ReplenishmentRequirement::query()
            ->where('warehouse_id', $allocation->warehouse_id)
            ->where('product_variant_id', $purchaseLine->product_variant_id)
            ->active()
            ->oldest('id')
            ->first();

        foreach ($existing as $coverage) {
            if (! $requirement instanceof ReplenishmentRequirement || $coverage->replenishment_requirement_id !== $requirement->getKey()) {
                $this->coverages->release($coverage);
            }
        }

        if (! $requirement instanceof ReplenishmentRequirement) {
            return;
        }

        $current = ReplenishmentCoverage::query()
            ->where('replenishment_requirement_id', $requirement->getKey())
            ->where('source_type', ReplenishmentCoverageSourceType::PurchaseOrderLine->value)
            ->where('source_id', $purchaseLine->getKey())
            ->where('status', ReplenishmentCoverageStatus::Active->value)
            ->first();
        $currentQuantity = $current instanceof ReplenishmentCoverage ? (float) $current->covered_base_quantity : 0.0;
        $availableCapacity = $requirement->remainingUncoveredQuantity() + $currentQuantity;
        $orderedBase = is_numeric($purchaseLine->base_quantity) ? (float) $purchaseLine->base_quantity : (float) $purchaseLine->quantity_ordered;
        $receivedBase = is_numeric($purchaseLine->received_base_quantity) ? (float) $purchaseLine->received_base_quantity : (float) $purchaseLine->quantity_received;
        $outstanding = max(0.0, round($orderedBase - $receivedBase, 6));
        $covered = min($outstanding, $availableCapacity);

        if ($covered <= 0) {
            if ($current instanceof ReplenishmentCoverage) {
                $this->coverages->release($current);
            }

            return;
        }

        $this->coverages->attach(
            $requirement,
            ReplenishmentCoverageSourceType::PurchaseOrderLine,
            (int) $purchaseLine->getKey(),
            $covered,
        );
    }

    public function releaseForOrder(PurchaseOrder $order): void
    {
        $lineIds = $order->lines()->pluck('id');

        if ($lineIds->isEmpty()) {
            return;
        }

        $coverages = ReplenishmentCoverage::query()
            ->with('requirement.policy')
            ->where('source_type', ReplenishmentCoverageSourceType::PurchaseOrderLine->value)
            ->whereIn('source_id', $lineIds)
            ->where('status', ReplenishmentCoverageStatus::Active->value)
            ->get();
        $policies = [];

        foreach ($coverages as $coverage) {
            $policy = $coverage->requirement->policy;

            if ($policy instanceof WarehouseReplenishmentPolicy) {
                $policies[$policy->getKey()] = $policy;
            }

            $this->coverages->release($coverage);
        }

        foreach ($policies as $policy) {
            $this->requirements->sync($policy);
        }
    }
}
