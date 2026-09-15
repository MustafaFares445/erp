<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\LogisticsInboundAllocationData;
use App\Data\Inventory\LogisticsInboundData;
use App\Data\Inventory\LogisticsInboundLineData;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\PurchaseInboundStatus;
use App\Models\InventoryOperationLine;
use App\Models\Product;
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
        $lines = array_values($inbound->lines
            ->map(fn (PurchaseInboundLine $line): LogisticsInboundLineData => $this->projectLine($line))
            ->values()
            ->all());

        $confirmed = $this->sum(array_map(static fn (LogisticsInboundLineData $line): string => $line->confirmedBaseQuantity, $lines));
        $allocated = $this->sum(array_map(static fn (LogisticsInboundLineData $line): string => $line->allocatedBaseQuantity, $lines));
        $received = $this->sum(array_map(static fn (LogisticsInboundLineData $line): string => $line->receivedBaseQuantity, $lines));
        $remaining = $this->sum(array_map(static fn (LogisticsInboundLineData $line): string => $line->remainingBaseQuantity, $lines));
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
            blockers: [],
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
        $allocations = array_values($line->allocations
            ->map(fn (PurchaseInboundAllocation $allocation): LogisticsInboundAllocationData => $this->allocationData($allocation))
            ->values()
            ->all());

        $received = $this->completedForLine($poLine);
        $inProgress = $this->openForLine($poLine);
        $remaining = $this->nonNegativeSubtract($quantities['confirmed'], $received);
        $availableToReceive = $this->sum(array_map(static fn (LogisticsInboundAllocationData $allocation): string => $allocation->availableToReceive, $allocations));
        $variant = $poLine->productVariant;
        $product = $variant->product;

        return new LogisticsInboundLineData(
            purchaseOrderLineId: $poLine->id,
            purchaseInboundLineId: $line->id,
            sku: $variant->sku,
            product: $product instanceof Product ? $product->name : $variant->name,
            uom: $variant->unit->symbol ?? $variant->unit->name ?? 'Base',
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
            blockers: [],
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
     * @param  array{
     *     currently_allocatable: numeric-string,
     * }  $quantities
     * @param  numeric-string  $remaining
     * @param  numeric-string  $availableToReceive
     * @param  numeric-string  $inProgress
     */
    private function lineNextAction(array $quantities, string $remaining, string $availableToReceive, string $inProgress): string
    {
        if (bccomp($quantities['currently_allocatable'], '0.000000', self::SCALE) === 1) {
            return 'Allocate quantity';
        }

        if (bccomp($inProgress, '0.000000', self::SCALE) === 1) {
            return 'Complete open receipt';
        }

        if (bccomp($availableToReceive, '0.000000', self::SCALE) === 1) {
            return 'Complete draft receipt';
        }

        return bccomp($remaining, '0.000000', self::SCALE) === 0 ? 'Completed' : 'Review inbound';
    }

    /**
     * @param  list<LogisticsInboundLineData>  $lines
     * @return list<string>
     */
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

    /**
     * @param  list<string>  $values
     * @return numeric-string
     */
    private function sum(array $values): string
    {
        $total = '0.000000';

        foreach ($values as $value) {
            $total = bcadd($total, $this->numericString($value), self::SCALE);
        }

        return $total;
    }

    /** @param list<LogisticsInboundLineData> $lines */
    private function anyPositive(array $lines, string $property): bool
    {
        return array_any($lines, fn (LogisticsInboundLineData $line): bool => bccomp($this->lineQuantity($line, $property), '0.000000', self::SCALE) === 1);
    }

    /** @param list<LogisticsInboundLineData> $lines */
    private function everyZero(array $lines, string $property): bool
    {
        return array_all($lines, fn (LogisticsInboundLineData $line): bool => bccomp($this->lineQuantity($line, $property), '0.000000', self::SCALE) === 0);
    }

    /** @return numeric-string */
    private function lineQuantity(LogisticsInboundLineData $line, string $property): string
    {
        return $this->numericString(match ($property) {
            'backorderedBaseQuantity' => $line->backorderedBaseQuantity,
            'availableToReceiveBaseQuantity' => $line->availableToReceiveBaseQuantity,
            'receivedBaseQuantity' => $line->receivedBaseQuantity,
            'currentlyAllocatableBaseQuantity' => $line->currentlyAllocatableBaseQuantity,
            'remainingBaseQuantity' => $line->remainingBaseQuantity,
            default => throw new \LogicException('Unsupported inbound quantity property.'),
        });
    }

    /**
     * @param  numeric-string  $left
     * @param  numeric-string  $right
     * @return numeric-string
     */
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

    /**
     * @param  list<OperationStage>  $stages
     * @return numeric-string
     */
    private function operationQuantityForLine(PurchaseOrderLine $line, array $stages): string
    {
        $quantity = InventoryOperationLine::query()
            ->where('purchase_order_line_id', $line->id)
            ->whereNotNull('base_quantity')
            ->whereHas('operation', static fn (Builder $query): Builder => $query
                ->where('operation_type', OperationType::Receipt->value)
                ->whereIn('stage', array_map(static fn (OperationStage $stage): string => $stage->value, $stages)))
            ->sum('base_quantity');

        return $this->decimal((string) $quantity);
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
            'Awaiting Allocation' => 'Allocate warehouse quantities',
            'Ready to Receive' => 'Complete draft receipt',
            'Partially Received' => 'Continue receiving',
            default => 'View details',
        };
    }

    /** @return numeric-string */
    private function decimal(string $value): string
    {
        if (! is_numeric($value)) {
            throw new \LogicException('An inbound quantity must be numeric.');
        }

        return bcadd('0.000000', $value, self::SCALE);
    }

    /** @return numeric-string */
    private function numericString(string $value): string
    {
        if (! is_numeric($value)) {
            throw new \LogicException('An inbound quantity must be numeric.');
        }

        return $value;
    }
}
