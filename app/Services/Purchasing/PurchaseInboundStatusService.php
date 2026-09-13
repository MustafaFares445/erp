<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\PurchaseInboundStatus;
use App\Models\InventoryOperationLine;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Support\Facades\DB;

/**
 * Derives PurchaseInbound lifecycle state from persisted business facts.
 *
 * Allocation state is based on the exact base quantity split across warehouses.
 * Receipt state is based only on completed Inventory receipt lines linked to the
 * purchase order; Draft/Ready operations are commitments, not physical receipt
 * facts, and therefore never advance the inbound lifecycle.
 */
final readonly class PurchaseInboundStatusService
{
    private const int QUANTITY_SCALE = 6;

    public function synchronize(PurchaseInbound $inbound): PurchaseInbound
    {
        $synchronize = function () use ($inbound): PurchaseInbound {
            /** @var PurchaseInbound $locked */
            $locked = PurchaseInbound::query()
                ->lockForUpdate()
                ->findOrFail($inbound->getKey());

            if ($locked->status === PurchaseInboundStatus::Cancelled) {
                return $locked;
            }

            $locked->load(['lines.purchaseOrderLine', 'lines.allocations']);
            $receivedByPurchaseOrderLine = $this->completedReceiptTotals($locked);

            $fullyAllocated = $locked->lines->isNotEmpty();
            $fullyReceived = $locked->lines->isNotEmpty();
            $hasReceived = false;

            foreach ($locked->lines as $line) {
                $purchaseOrderLine = $line->purchaseOrderLine;

                if (
                    ! $purchaseOrderLine instanceof PurchaseOrderLine
                    || $purchaseOrderLine->base_quantity === null
                    || ! is_numeric($purchaseOrderLine->base_quantity)
                ) {
                    $fullyAllocated = false;
                    $fullyReceived = false;

                    continue;
                }

                $inboundQuantity = bcadd(
                    '0.000000',
                    (string) $purchaseOrderLine->base_quantity,
                    self::QUANTITY_SCALE,
                );

                $allocated = '0.000000';
                $allocationQuantitiesKnown = true;

                foreach ($line->allocations as $allocation) {
                    if ($allocation->allocated_base_quantity === null) {
                        $allocationQuantitiesKnown = false;

                        continue;
                    }

                    $allocated = bcadd(
                        $allocated,
                        (string) $allocation->allocated_base_quantity,
                        self::QUANTITY_SCALE,
                    );
                }

                if (
                    ! $allocationQuantitiesKnown
                    || bccomp($allocated, $inboundQuantity, self::QUANTITY_SCALE) !== 0
                ) {
                    $fullyAllocated = false;
                }

                $received = $receivedByPurchaseOrderLine[(int) $purchaseOrderLine->getKey()] ?? '0.000000';

                if (bccomp($received, '0.000000', self::QUANTITY_SCALE) === 1) {
                    $hasReceived = true;
                }

                if (bccomp($received, $inboundQuantity, self::QUANTITY_SCALE) !== 0) {
                    $fullyReceived = false;
                }
            }

            $target = match (true) {
                $fullyReceived => PurchaseInboundStatus::Received,
                $hasReceived => PurchaseInboundStatus::PartiallyReceived,
                $fullyAllocated => PurchaseInboundStatus::AwaitingReceipt,
                default => PurchaseInboundStatus::AwaitingAllocation,
            };

            $attributes = ['status' => $target];

            if ($fullyAllocated && $locked->allocation_confirmed_at === null) {
                $attributes['allocation_confirmed_at'] = now();
            } elseif (
                ! $fullyAllocated
                && ! $hasReceived
                && $target === PurchaseInboundStatus::AwaitingAllocation
            ) {
                // A pre-receipt allocation was reopened before any physical
                // receipt happened, so confirmation is no longer true.
                $attributes['allocation_confirmed_at'] = null;
            }

            if ($target === PurchaseInboundStatus::Received) {
                $attributes['completed_at'] = $locked->completed_at ?? now();
            } else {
                $attributes['completed_at'] = null;
            }

            $locked->forceFill($attributes);

            if ($locked->isDirty()) {
                $locked->save();
            }

            return $locked->refresh();
        };

        if (DB::transactionLevel() > 0) {
            return $synchronize();
        }

        return DB::transaction($synchronize, attempts: 5);
    }

    /**
     * Aggregate completed physical receipts by commercial PO line. This counts
     * receipts across every warehouse allocation and also remains correct for a
     * deterministic legacy receipt whose allocation provenance was backfilled at
     * completion time.
     *
     * @return array<int, numeric-string>
     */
    private function completedReceiptTotals(PurchaseInbound $inbound): array
    {
        $purchaseOrderLineIds = $inbound->lines
            ->map(static fn (PurchaseInboundLine $line): int => (int) $line->purchase_order_line_id)
            ->unique()
            ->values();

        if ($purchaseOrderLineIds->isEmpty()) {
            return [];
        }

        $rows = InventoryOperationLine::query()
            ->selectRaw('purchase_order_line_id, SUM(base_quantity) AS received_base_quantity')
            ->whereIn('purchase_order_line_id', $purchaseOrderLineIds)
            ->whereNotNull('base_quantity')
            ->whereHas('operation', static fn ($query) => $query
                ->where('operation_type', OperationType::Receipt->value)
                ->where('stage', OperationStage::Done->value)
                ->where('source_document_type', PurchaseOrder::class)
                ->where('source_document_id', $inbound->purchase_order_id))
            ->groupBy('purchase_order_line_id')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $purchaseOrderLineId = $row->purchase_order_line_id;
            $receivedBaseQuantity = $row->getAttribute('received_base_quantity');
            if (! is_numeric($purchaseOrderLineId)) {
                continue;
            }
            if (! is_numeric($receivedBaseQuantity)) {
                continue;
            }

            $totals[(int) $purchaseOrderLineId] = bcadd(
                '0.000000',
                (string) $receivedBaseQuantity,
                self::QUANTITY_SCALE,
            );
        }

        return $totals;
    }
}
