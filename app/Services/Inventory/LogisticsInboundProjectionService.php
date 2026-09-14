<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\LogisticsInboundAllocationData;
use App\Data\Inventory\LogisticsInboundBlockerData;
use App\Data\Inventory\LogisticsInboundData;
use App\Data\Inventory\LogisticsInboundLineData;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\PurchaseInboundStatus;
use App\Models\InventoryOperationLine;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use App\Services\Purchasing\PurchaseOrderSupplierCommitmentService;
use Illuminate\Database\Eloquent\Builder;

final readonly class LogisticsInboundProjectionService
{
    private const int SCALE = 6;

    public function __construct(
        private PurchaseOrderSupplierCommitmentService $commitments,
        private PurchaseOrderReceivingService $receiving,
    ) {}

    public function project(PurchaseInbound $inbound): LogisticsInboundData
    {
        $inbound->loadMissing([
            'purchaseOrder.supplier',
            'lines.purchaseOrderLine.productVariant.product',
            'lines.purchaseOrderLine.productVariant.unit',
            'lines.allocations.warehouse',
        ]);

        $order = $inbound->purchaseOrder;
        $lines = $inbound->lines
            ->map(fn (PurchaseInboundLine $line): LogisticsInboundLineData => $this->projectLine($line))
            ->values()
            ->all();

        $confirmed = $this->sum($lines, 'confirmedBaseQuantity');
        $allocated = $this->sum($lines, 'allocatedBaseQuantity');
        $received = $this->sum($lines, 'receivedBaseQuantity');
        $remaining = $this->sum($lines, 'remainingBaseQuantity');
        $blockers = $this->headerBlockers($lines);
        $state = $this->businessState($inbound, $lines);

        return new LogisticsInboundData(
            purchaseInboundId: $inbound->id,
            purchaseOrderId: $order->id,
            purchaseOrderReference: $order->purchase_order_number,
            supplier: $order->supplier->name,
            expectedAt: $order->expected_at,
            inboundStatus: $inbound->status,
            businessState: $state,
            overdue: $this->isOverdue($order, $remaining),
            confirmedBaseQuantity: $confirmed,
            allocatedBaseQuantity: $allocated,
            receivedBaseQuantity: $received,
            remainingBaseQuantity: $remaining,
            destinationWarehouses: $this->warehouses($lines),
            blockers: $blockers,
            lines: $lines,
            nextAction: $this->nextAction($state),
        );
    }

    public function projectLine(PurchaseInboundLine $line): LogisticsInboundLineData
    {
        $line->loadMissing([
            'purchaseOrderLine.productVariant.product',
            'purchaseOrderLine.productVariant.unit',
            'allocations.warehouse',
        ]);

        $poLine = $line->purchaseOrderLine;
        $quantities = $this->commitments->quantities($poLine);
        $allocations = $line->allocations
            ->map(fn (PurchaseInboundAllocation $allocation): LogisticsInboundAllocationData => $this->allocationData($allocation))
            ->values()
            ->all();

        $received = $this->completedForLine($poLine);
        $inProgress = $this->openForLine($poLine);
        $remaining = $this->nonNegativeSubtract($quantities['confirmed'], $received);
        $availableToReceive = $this->sum($allocations, 'availableToReceive');
        $blockers = [];

        if ($quantities['awaiting_confirmation']) {
            $blockers[] = new LogisticsInboundBlockerData(
                'supplier_confirmation_pending',
                'Supplier confirmation is required before this line can be allocated.',
            );
        }

        if ($quantities['over_allocated']) {
            $blockers[] = new LogisticsInboundBlockerData(
                'supplier_commitment_below_allocation',
                'Supplier commitment is now below quantity already allocated.',
                'danger',
            );
        }

        $variant = $poLine->productVariant;
        $product = $variant->product;

        return new LogisticsInboundLineData(
            purchaseOrderLineId: $poLine->id,
            purchaseInboundLineId: $line->id,
            sku: $variant->sku,
            product: $product?->name ?? $variant->name,
            uom: $variant->unit?->symbol ?? $variant->unit?->name ?? 'Base',
            orderedBaseQuantity: $quantities['ordered'],
            confirmedBaseQuantity: $quantities['confirmed'],
            backorderedBaseQuantity: $quantities['backordered'],
            allocatedBaseQuantity: $quantities['allocated'],
            receivedBaseQuantity: $received,
            receiptInProgressBaseQuantity: $inProgress,
            remainingBaseQuantity: $remaining,
            currentlyAllocatableBaseQuantity: $quantities['currently_allocatable'],
            availableToReceiveBaseQuantity: $availableToReceive,
            allocations: $allocations,
            blockers: $blockers,
            nextAction: $this->lineNextAction($quantities, $remaining, $availableToReceive, $inProgress),
        );
    }

    private function allocationData(PurchaseInboundAllocation $allocation): LogisticsInboundAllocationData
    {
        $received = $allocation->receivedBaseQuantity();
        $available = $this->receiving->availableBaseQuantityForAllocation($allocation);
        $inProgress = $this->nonNegativeSubtract(
            $this->nonNegativeSubtract($allocation->allocated_base_quantity ?? '0.000000', $received),
            $available,
        );

        return new LogisticsInboundAllocationData(
            id: $allocation->id,
            warehouseId: $allocation->warehouse_id,
            warehouse: $allocation->warehouse->name,
            allocated: $allocation->allocated_base_quantity ?? '0.000000',
            received: $received,
            receiptInProgress: $inProgress,
            availableToReceive: $available,
        );
    }

    /** @param list<LogisticsInboundLineData> $lines */
    private function businessState(PurchaseInbound $inbound, array $lines): string
    {
        if ($inbound->status === PurchaseInboundStatus::Cancelled) {
            return 'Cancelled / Closed';
        }

        if ($this->hasBlocker($lines, 'supplier_commitment_below_allocation')) {
            return 'Needs Attention';
        }

        if ($this->hasBlocker($lines, 'supplier_confirmation_pending')) {
            return 'Awaiting Supplier Confirmation';
        }

        if ($this->anyPositive($lines, 'backorderedBaseQuantity')) {
            return 'Waiting for Supplier Backorder';
        }

        if ($lines !== [] && $this->everyZero($lines, 'remainingBaseQuantity')) {
            return 'Received';
        }

        if ($this->anyPositive($lines, 'availableToReceiveBaseQuantity')) {
            return 'Ready to Receive';
        }

        if ($this->anyPositive($lines, 'receivedBaseQuantity')) {
            return 'Partially Received';
        }

        if ($this->anyPositive($lines, 'currentlyAllocatableBaseQuantity')) {
            return 'Awaiting Allocation';
        }

        return 'Awaiting Allocation';
    }

    /**
     * @param  array<string, mixed>  $quantities
     * @param  numeric-string  $remaining
     * @param  numeric-string  $availableToReceive
     * @param  numeric-string  $inProgress
     */
    private function lineNextAction(array $quantities, string $remaining, string $availableToReceive, string $inProgress): string
    {
        if ($quantities['awaiting_confirmation']) {
            return 'Wait for supplier confirmation';
        }

        if ($quantities['over_allocated']) {
            return 'Resolve supplier commitment blocker';
        }

        if (bccomp($quantities['currently_allocatable'], '0.000000', self::SCALE) === 1) {
            return 'Allocate quantity';
        }

        if (bccomp($inProgress, '0.000000', self::SCALE) === 1) {
            return 'Complete open receipt';
        }

        if (bccomp($availableToReceive, '0.000000', self::SCALE) === 1) {
            return 'Receive goods';
        }

        if (bccomp($remaining, '0.000000', self::SCALE) === 0 && bccomp($quantities['backordered'], '0.000000', self::SCALE) === 1) {
            return 'Wait for supplier backorder';
        }

        return bccomp($remaining, '0.000000', self::SCALE) === 0 ? 'Completed' : 'Review inbound';
    }

    /** @param list<LogisticsInboundLineData> $lines @return list<LogisticsInboundBlockerData> */
    private function headerBlockers(array $lines): array
    {
        $unique = [];

        foreach ($lines as $line) {
            foreach ($line->blockers as $blocker) {
                $unique[$blocker->code] = $blocker;
            }
        }

        return array_values($unique);
    }

    /** @param list<LogisticsInboundLineData> $lines @return list<string> */
    private function warehouses(array $lines): array
    {
        $warehouses = [];

        foreach ($lines as $line) {
            foreach ($line->allocations as $allocation) {
                $warehouses[$allocation->warehouseId] = $allocation->warehouse;
            }
        }

        return array_values($warehouses);
    }

    /** @param list<LogisticsInboundLineData> $lines */
    private function hasBlocker(array $lines, string $code): bool
    {
        foreach ($lines as $line) {
            foreach ($line->blockers as $blocker) {
                if ($blocker->code === $code) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<object> $items */
    private function sum(array $items, string $property): string
    {
        $total = '0.000000';

        foreach ($items as $item) {
            $value = $item->{$property};
            $total = bcadd($total, $value, self::SCALE);
        }

        return $total;
    }

    /** @param list<LogisticsInboundLineData> $lines */
    private function anyPositive(array $lines, string $property): bool
    {
        return array_any($lines, fn (LogisticsInboundLineData $line): bool => bccomp($line->{$property}, '0.000000', self::SCALE) === 1);
    }

    /** @param list<LogisticsInboundLineData> $lines */
    private function everyZero(array $lines, string $property): bool
    {
        return array_all($lines, fn (LogisticsInboundLineData $line): bool => bccomp($line->{$property}, '0.000000', self::SCALE) === 0);
    }

    /** @param numeric-string $left @param numeric-string $right @return numeric-string */
    private function nonNegativeSubtract(string $left, string $right): string
    {
        $result = bcsub($left, $right, self::SCALE);

        return bccomp($result, '0.000000', self::SCALE) === -1 ? '0.000000' : $result;
    }

    /** @return numeric-string */
    private function completedForLine(PurchaseOrderLine $line): string
    {
        return $this->operationQuantityForLine($line, [OperationStage::Done]);
    }

    /** @return numeric-string */
    private function openForLine(PurchaseOrderLine $line): string
    {
        return $this->operationQuantityForLine($line, [
            OperationStage::Draft,
            OperationStage::Waiting,
            OperationStage::Ready,
        ]);
    }

    /** @param list<OperationStage> $stages @return numeric-string */
    private function operationQuantityForLine(PurchaseOrderLine $line, array $stages): string
    {
        $quantity = InventoryOperationLine::query()
            ->where('purchase_order_line_id', $line->id)
            ->whereNotNull('base_quantity')
            ->whereHas('operation', static fn (Builder $query): Builder => $query
                ->where('operation_type', OperationType::Receipt->value)
                ->whereIn('stage', array_map(static fn (OperationStage $stage): string => $stage->value, $stages)))
            ->sum('base_quantity');

        return bcadd('0.000000', (string) $quantity, self::SCALE);
    }

    /** @param numeric-string $remaining */
    private function isOverdue(PurchaseOrder $order, string $remaining): bool
    {
        return $order->expected_at !== null
            && $order->expected_at->isPast()
            && ! $order->expected_at->isToday()
            && bccomp($remaining, '0.000000', self::SCALE) === 1;
    }

    private function nextAction(string $state): string
    {
        return match ($state) {
            'Needs Attention' => 'Resolve blocker',
            'Awaiting Supplier Confirmation' => 'Wait for supplier confirmation',
            'Awaiting Allocation' => 'Allocate warehouse quantities',
            'Ready to Receive' => 'Receive goods',
            'Partially Received' => 'Continue receiving',
            'Waiting for Supplier Backorder' => 'Wait for supplier backorder',
            default => 'View details',
        };
    }
}
