<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\PurchaseOrderStatus;
use App\Events\InventoryOperationCompleted;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\Inventory\PurchaseReplenishmentCoverageService;
use App\Services\Purchasing\Exceptions\InvalidPurchaseInboundReceipt;
use App\Services\Purchasing\Exceptions\OverReceiptRejected;
use App\Services\Purchasing\PurchaseInboundStatusService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reconciles completed Inventory receipts back to Purchasing.
 *
 * This listener runs synchronously inside InventoryOperationService's completion
 * transaction. Allocation provenance, allocation limits, PO-line limits, stock
 * posting, PurchaseInbound status, and purchase-order status therefore commit
 * together or roll back together.
 */
final readonly class AdvancePurchaseOrderOnOperationCompleted
{
    private const int QUANTITY_SCALE = 6;

    public function __construct(
        private PurchaseReplenishmentCoverageService $replenishmentCoverage,
        private PurchaseInboundStatusService $inboundStatus,
    ) {}

    public function handle(InventoryOperationCompleted $event): void
    {
        $operation = $event->operation;
        $order = $this->purchaseOrderFor($operation);

        if (! $order instanceof PurchaseOrder) {
            return;
        }

        [$lines, $allocations] = $this->lockAndValidateAllocationContext($operation, $order);
        $incoming = $this->receivedQuantitiesByPurchaseOrderLine($operation);

        // Preserve the PO-level hard over-receipt contract first. Allocation
        // limits are the narrower warehouse constraint and are checked next.
        $this->assertNoOverReceipt($lines, $incoming);
        $this->assertAllocationsNotOverReceived($allocations);
        $this->applyReceipts($lines, $incoming);

        /** @var PurchaseInbound|null $inbound */
        $inbound = PurchaseInbound::query()
            ->where('purchase_order_id', $order->getKey())
            ->first();

        if ($inbound instanceof PurchaseInbound) {
            $this->inboundStatus->synchronize($inbound);
        }

        $this->advanceStatus($order, $event->actor);
        $this->replenishmentCoverage->syncForOrder($order->refresh());
    }

    private function purchaseOrderFor(InventoryOperation $operation): ?PurchaseOrder
    {
        if ($operation->operation_type !== OperationType::Receipt) {
            return null;
        }

        if ($operation->source_document_type !== PurchaseOrder::class) {
            return null;
        }

        /** @var PurchaseOrder|null $order */
        $order = PurchaseOrder::query()
            ->lockForUpdate()
            ->find($operation->source_document_id);

        return $order;
    }

    /**
     * Resolve legacy provenance only when PO line + destination warehouse maps
     * to exactly one allocation, then lock Purchasing rows in the canonical
     * order: inbound aggregate → inbound lines → PO lines → allocations.
     *
     * @return array{0: Collection<int, PurchaseOrderLine>, 1: Collection<int, PurchaseInboundAllocation>}
     */
    private function lockAndValidateAllocationContext(
        InventoryOperation $operation,
        PurchaseOrder $order,
    ): array {
        /** @var Collection<int, InventoryOperationLine> $operationLines */
        $operationLines = $operation->lines()->orderBy('id')->get();
        $purchaseLines = $operationLines->filter(
            static fn (InventoryOperationLine $line): bool => $line->purchase_order_line_id !== null
                || $line->purchase_inbound_allocation_id !== null,
        );

        if ($purchaseLines->isEmpty()) {
            /** @var Collection<int, PurchaseOrderLine> $lockedPurchaseOrderLines */
            $lockedPurchaseOrderLines = $order->lines()
                ->with('productVariant')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /** @var Collection<int, PurchaseInboundAllocation> $noAllocations */
            $noAllocations = new Collection;

            return [$lockedPurchaseOrderLines, $noAllocations];
        }

        /** @var PurchaseInbound|null $inbound */
        $inbound = PurchaseInbound::query()
            ->where('purchase_order_id', $order->getKey())
            ->lockForUpdate()
            ->first();

        if (! $inbound instanceof PurchaseInbound) {
            throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
        }

        $destinationWarehouseId = $operation->destination_warehouse_id;

        if (! is_int($destinationWarehouseId)) {
            throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
        }

        $purchaseOrderLineIds = $purchaseLines
            ->pluck('purchase_order_line_id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $inboundLines = PurchaseInboundLine::query()
            ->where('purchase_inbound_id', $inbound->getKey())
            ->whereIn('purchase_order_line_id', $purchaseOrderLineIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('purchase_order_line_id');

        $lockedPurchaseOrderLines = PurchaseOrderLine::query()
            ->where('purchase_order_id', $order->getKey())
            ->whereIn('id', $purchaseOrderLineIds)
            ->with('productVariant')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($lockedPurchaseOrderLines->count() !== $purchaseOrderLineIds->count()) {
            throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
        }

        $resolvedAllocationIds = [];

        foreach ($purchaseLines as $operationLine) {
            if ($operationLine->purchase_order_line_id === null) {
                throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
            }

            $allocationId = $operationLine->purchase_inbound_allocation_id;

            if ($allocationId === null) {
                /** @var PurchaseInboundLine|null $inboundLine */
                $inboundLine = $inboundLines->get($operationLine->purchase_order_line_id);

                if (! $inboundLine instanceof PurchaseInboundLine) {
                    throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
                }

                $candidateIds = PurchaseInboundAllocation::query()
                    ->where('purchase_inbound_line_id', $inboundLine->getKey())
                    ->where('warehouse_id', $destinationWarehouseId)
                    ->orderBy('id')
                    ->limit(2)
                    ->pluck('id');

                if ($candidateIds->count() !== 1) {
                    throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
                }

                $allocationId = (int) $candidateIds->first();
            }

            $resolvedAllocationIds[(int) $operationLine->getKey()] = (int) $allocationId;
        }

        $allocationIds = collect($resolvedAllocationIds)
            ->values()
            ->unique()
            ->sort()
            ->values();

        $allocations = PurchaseInboundAllocation::query()
            ->whereIn('id', $allocationIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($allocations->count() !== $allocationIds->count()) {
            throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
        }

        foreach ($purchaseLines as $operationLine) {
            $allocationId = $resolvedAllocationIds[(int) $operationLine->getKey()];
            /** @var PurchaseInboundAllocation|null $allocation */
            $allocation = $allocations->get($allocationId);
            /** @var PurchaseInboundLine|null $inboundLine */
            $inboundLine = $inboundLines->get($operationLine->purchase_order_line_id);

            if (! $allocation instanceof PurchaseInboundAllocation || ! $inboundLine instanceof PurchaseInboundLine) {
                throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
            }

            if ((int) $allocation->purchase_inbound_line_id !== (int) $inboundLine->getKey()) {
                throw InvalidPurchaseInboundReceipt::purchaseOrderLineMismatch($allocation);
            }

            if ((int) $allocation->warehouse_id !== $destinationWarehouseId) {
                throw InvalidPurchaseInboundReceipt::warehouseMismatch($allocation);
            }

            if ($allocation->allocated_base_quantity === null) {
                throw InvalidPurchaseInboundReceipt::unresolvedAllocationQuantity($allocation);
            }

            if ($operationLine->purchase_inbound_allocation_id === null) {
                $operationLine->forceFill(['purchase_inbound_allocation_id' => $allocation->getKey()])->save();
            }
        }

        return [$lockedPurchaseOrderLines, $allocations];
    }

    /**
     * Counts completed and in-flight non-cancelled provenance lines. This makes
     * a receipt line an allocation reservation as soon as it exists, while the
     * completion-time check protects against a draft quantity being edited after
     * initiation.
     *
     * @param  Collection<int, PurchaseInboundAllocation>  $allocations
     */
    private function assertAllocationsNotOverReceived(Collection $allocations): void
    {
        foreach ($allocations as $allocation) {
            if ($allocation->allocated_base_quantity === null) {
                throw InvalidPurchaseInboundReceipt::unresolvedAllocationQuantity($allocation);
            }

            $reservedOrReceived = InventoryOperationLine::query()
                ->where('purchase_inbound_allocation_id', $allocation->getKey())
                ->whereNotNull('base_quantity')
                ->whereHas('operation', static fn ($query) => $query
                    ->where('operation_type', OperationType::Receipt->value)
                    ->where('stage', '!=', OperationStage::Canceled->value))
                ->sum('base_quantity');

            $total = bcadd('0.000000', (string) $reservedOrReceived, self::QUANTITY_SCALE);
            $allocated = (string) $allocation->allocated_base_quantity;

            if (bccomp($total, $allocated, self::QUANTITY_SCALE) === 1) {
                throw InvalidPurchaseInboundReceipt::allocationOverReceived($allocation, $total, $allocated);
            }
        }
    }

    /**
     * @return array<int, array{base_quantity: numeric-string}>
     */
    private function receivedQuantitiesByPurchaseOrderLine(InventoryOperation $operation): array
    {
        $totals = [];

        foreach ($operation->lines()->get() as $line) {
            if ($line->purchase_order_line_id === null) {
                continue;
            }

            if ($line->base_quantity === null) {
                throw new OverReceiptRejected('A purchase-order receipt line requires a base-quantity source reference.');
            }

            $key = $line->purchase_order_line_id;

            $totals[$key] ??= ['base_quantity' => '0.000000'];
            $totals[$key]['base_quantity'] = bcadd($totals[$key]['base_quantity'], $line->base_quantity, self::QUANTITY_SCALE);
        }

        return $totals;
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @param  array<int, array{base_quantity: numeric-string}>  $incoming
     */
    private function assertNoOverReceipt(Collection $lines, array $incoming): void
    {
        foreach ($lines as $line) {
            $received = $incoming[$line->id]['base_quantity'] ?? '0.000000';

            if (bccomp($received, '0', self::QUANTITY_SCALE) <= 0) {
                continue;
            }

            if ($line->base_quantity === null) {
                throw new OverReceiptRejected('A purchase-order line requires a base quantity.');
            }

            $alreadyReceived = $line->received_base_quantity ?? '0.000000';

            if (bccomp(bcadd($alreadyReceived, $received, self::QUANTITY_SCALE), $line->base_quantity, self::QUANTITY_SCALE) === 1) {
                throw OverReceiptRejected::forLine($line, (float) $received);
            }
        }
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @param  array<int, array{base_quantity: numeric-string}>  $incoming
     */
    private function applyReceipts(Collection $lines, array $incoming): void
    {
        foreach ($lines as $line) {
            $entry = $incoming[$line->id] ?? null;
            if ($entry === null) {
                continue;
            }
            if (bccomp($entry['base_quantity'], '0', self::QUANTITY_SCALE) <= 0) {
                continue;
            }

            if ($line->conversion_factor_snapshot === null) {
                throw new OverReceiptRejected('A purchase-order line requires a conversion snapshot.');
            }

            $receivedBaseQuantity = bcadd(
                $line->received_base_quantity ?? '0.000000',
                $entry['base_quantity'],
                self::QUANTITY_SCALE,
            );

            $line->forceFill([
                'received_base_quantity' => $receivedBaseQuantity,
                'quantity_received' => bcdiv($receivedBaseQuantity, $line->conversion_factor_snapshot, self::QUANTITY_SCALE),
            ])->save();
        }
    }

    private function advanceStatus(PurchaseOrder $order, ?User $actor): void
    {
        $order->load('lines');

        $target = $order->lines->every(static fn (PurchaseOrderLine $line): bool => $line->isFullyReceived())
            ? PurchaseOrderStatus::Received
            : PurchaseOrderStatus::PartiallyReceived;

        if (! $order->status->canTransitionTo($target)) {
            return;
        }

        $order->forceFill([
            'status' => $target,
            'updated_by' => $actor?->getKey() ?? $order->updated_by,
        ])->save();

        activity()
            ->performedOn($order)
            ->causedBy($actor)
            ->withChanges(['attributes' => ['status' => $target->value]])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('purchasing.order.received');
    }
}
