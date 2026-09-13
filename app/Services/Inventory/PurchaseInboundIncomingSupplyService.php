<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryOperationLine;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Collection;

/**
 * Canonical read model for purchase supply that is still incoming to a warehouse.
 *
 * A purchase-order line may now be split across warehouses. This service keeps
 * both replenishment projection and coverage on the same rule: incoming supply
 * belongs to a PurchaseInboundAllocation and equals allocated base quantity less
 * completed physical receipts for that allocation.
 *
 * Historical rows with an unknown allocation quantity are used only when the
 * inbound line has exactly one allocation, because only then can the PO line's
 * total quantity and received quantity be assigned to that warehouse without
 * inventing a distribution.
 */
final readonly class PurchaseInboundIncomingSupplyService
{
    private const int QUANTITY_SCALE = 6;

    /** @return numeric-string|null */
    public function remainingForAllocation(PurchaseInboundAllocation $allocation): ?string
    {
        $allocation->loadMissing([
            'purchaseInboundLine.purchaseOrderLine',
            'purchaseInboundLine.allocations',
        ]);

        $line = $allocation->purchaseInboundLine;
        $purchaseLine = $line->purchaseOrderLine;

        if ($line->allocations->count() === 1) {
            return $this->singleAllocationRemaining($purchaseLine, $allocation);
        }

        if ($allocation->allocated_base_quantity === null) {
            return null;
        }

        return $this->nonNegativeDifference(
            $allocation->allocated_base_quantity,
            $this->completedReceivedForAllocation($allocation->id),
        );
    }

    /** @return numeric-string */
    public function totalForWarehouseProduct(int $warehouseId, int $productVariantId): string
    {
        /** @var Collection<int, PurchaseInboundAllocation> $allocations */
        $allocations = PurchaseInboundAllocation::query()
            ->where('warehouse_id', $warehouseId)
            ->whereHas('purchaseInboundLine.purchaseOrderLine', function ($query) use ($productVariantId): void {
                $query
                    ->where('product_variant_id', $productVariantId)
                    ->whereHas('purchaseOrder', static fn ($orderQuery) => $orderQuery->whereIn('status', [
                        PurchaseOrderStatus::Accepted->value,
                        PurchaseOrderStatus::PartiallyReceived->value,
                    ]));
            })
            ->with([
                'purchaseInboundLine.purchaseOrderLine',
                'purchaseInboundLine.allocations',
            ])
            ->orderBy('id')
            ->get();

        if ($allocations->isEmpty()) {
            return '0.000000';
        }

        $receivedByAllocation = $this->completedReceivedByAllocation($allocations);
        /** @var numeric-string $total */
        $total = '0.000000';

        foreach ($allocations as $allocation) {
            $line = $allocation->purchaseInboundLine;
            $purchaseLine = $line->purchaseOrderLine;

            if ($line->allocations->count() === 1) {
                $remaining = $this->singleAllocationRemaining($purchaseLine, $allocation);
            } elseif ($allocation->allocated_base_quantity === null) {
                $remaining = null;
            } else {
                $remaining = $this->nonNegativeDifference(
                    $allocation->allocated_base_quantity,
                    $receivedByAllocation[$allocation->id] ?? '0.000000',
                );
            }

            if ($remaining !== null) {
                $total = bcadd($total, $remaining, self::QUANTITY_SCALE);
            }
        }

        return $total;
    }

    /** @return numeric-string|null */
    private function singleAllocationRemaining(
        PurchaseOrderLine $purchaseLine,
        PurchaseInboundAllocation $allocation,
    ): ?string {
        $allocated = $allocation->allocated_base_quantity ?? $purchaseLine->base_quantity;

        if ($allocated === null || $purchaseLine->received_base_quantity === null) {
            return null;
        }

        return $this->nonNegativeDifference(
            $allocated,
            $purchaseLine->received_base_quantity,
        );
    }

    /** @return numeric-string */
    private function completedReceivedForAllocation(int $allocationId): string
    {
        $received = InventoryOperationLine::query()
            ->where('purchase_inbound_allocation_id', $allocationId)
            ->whereNotNull('base_quantity')
            ->whereHas('operation', static fn ($query) => $query
                ->where('operation_type', OperationType::Receipt->value)
                ->where('stage', OperationStage::Done->value))
            ->sum('base_quantity');

        if (! is_numeric($received)) {
            return '0.000000';
        }

        /** @var numeric-string $quantity */
        $quantity = (string) $received;

        return $this->decimal($quantity);
    }

    /**
     * @param  Collection<int, PurchaseInboundAllocation>  $allocations
     * @return array<int, numeric-string>
     */
    private function completedReceivedByAllocation(Collection $allocations): array
    {
        $rows = InventoryOperationLine::query()
            ->selectRaw('purchase_inbound_allocation_id, SUM(base_quantity) AS received_base_quantity')
            ->whereIn('purchase_inbound_allocation_id', $allocations->modelKeys())
            ->whereNotNull('base_quantity')
            ->whereHas('operation', static fn ($query) => $query
                ->where('operation_type', OperationType::Receipt->value)
                ->where('stage', OperationStage::Done->value))
            ->groupBy('purchase_inbound_allocation_id')
            ->get();

        /** @var array<int, numeric-string> $totals */
        $totals = [];

        foreach ($rows as $row) {
            $allocationId = $row->getAttribute('purchase_inbound_allocation_id');
            $received = $row->getAttribute('received_base_quantity');

            if (! is_numeric($allocationId) || ! is_numeric($received)) {
                continue;
            }

            /** @var numeric-string $receivedQuantity */
            $receivedQuantity = (string) $received;
            $totals[(int) $allocationId] = $this->decimal($receivedQuantity);
        }

        return $totals;
    }

    /**
     * @param  numeric-string  $minuend
     * @param  numeric-string  $subtrahend
     * @return numeric-string
     */
    private function nonNegativeDifference(string $minuend, string $subtrahend): string
    {
        $difference = bcsub($minuend, $subtrahend, self::QUANTITY_SCALE);

        return bccomp($difference, '0.000000', self::QUANTITY_SCALE) === -1
            ? '0.000000'
            : $difference;
    }

    /**
     * @param  numeric-string  $quantity
     * @return numeric-string
     */
    private function decimal(string $quantity): string
    {
        return bcadd('0.000000', $quantity, self::QUANTITY_SCALE);
    }
}
