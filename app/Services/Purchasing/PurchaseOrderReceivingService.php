<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Data\Inventory\NormalizedQuantity;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Purchasing\Exceptions\InvalidPurchaseInboundReceipt;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotAllocated;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotReceivable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Opens draft inventory receipts that consume explicit purchase inbound allocations.
 *
 * Purchasing establishes provenance and quantity limits; Inventory remains the
 * only boundary that can post stock. No bill, AP record, or accounting entry is
 * created here.
 */
final readonly class PurchaseOrderReceivingService
{
    private const int QUANTITY_SCALE = 6;

    public function __construct(
        private QuantityNormalizer $quantityNormalizer,
    ) {}

    /**
     * @param  list<array{purchase_inbound_allocation_id: int, quantity: string|int}>|null  $receiptLines
     *
     * When `$receiptLines` is null, backward compatibility is allowed only when
     * the remaining eligible allocations resolve deterministically to one
     * allocation. Explicit multi-warehouse callers must identify every allocation
     * and base-unit quantity they intend to receive.
     */
    public function initiate(User $actor, PurchaseOrder $order, ?array $receiptLines = null): InventoryOperation
    {
        Gate::forUser($actor)->authorize('receive', $order);

        return DB::transaction(function () use ($actor, $order, $receiptLines): InventoryOperation {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if (! $locked->status->isReceivable()) {
                throw PurchaseOrderNotReceivable::status($locked);
            }

            $legacyFallback = $receiptLines === null;
            $requests = $legacyFallback
                ? $this->deterministicRequests($locked)
                : $this->normalizeRequests($receiptLines);

            $prepared = $this->prepareReceiptLines($locked, $requests, $legacyFallback);

            if ($legacyFallback && count($prepared) !== 1) {
                throw PurchaseOrderNotAllocated::ambiguous($locked);
            }

            $warehouseIds = array_values(array_unique(array_map(
                static fn (array $line): int => (int) $line['warehouse']->getKey(),
                $prepared,
            )));

            if (count($warehouseIds) !== 1) {
                if ($legacyFallback) {
                    throw PurchaseOrderNotAllocated::ambiguous($locked);
                }

                throw InvalidPurchaseInboundReceipt::mixedWarehouses();
            }

            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouseIds[0]);
            $this->assertWarehouseIsUsable($warehouse);

            $operation = new InventoryOperation([
                'operation_type' => OperationType::Receipt,
                'destination_warehouse_id' => $warehouse->getKey(),
                'supplier_id' => $locked->supplier_id,
                'source_document_type' => PurchaseOrder::class,
                'source_document_id' => $locked->getKey(),
                'supplier_reference' => $locked->purchase_order_number,
            ]);

            $operation->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            foreach ($prepared as $preparedLine) {
                $purchaseOrderLine = $preparedLine['purchase_order_line'];
                $snapshot = $preparedLine['snapshot'];
                $baseQuantity = $preparedLine['base_quantity'];
                $allocation = $preparedLine['allocation'];

                $operation->lines()->create([
                    'product_variant_id' => $purchaseOrderLine->product_variant_id,
                    // Allocation quantities are canonical base quantities. The
                    // generated Inventory line therefore uses the base UOM so a
                    // warehouse split never has to be representable as a whole
                    // commercial purchase UOM (for example 50 pieces of a box-100 PO).
                    'unit_id' => $snapshot->baseUnitId,
                    'quantity' => $baseQuantity,
                    'transaction_quantity' => $baseQuantity,
                    'transaction_unit_id' => $snapshot->baseUnitId,
                    'conversion_factor_snapshot' => '1.000000',
                    'base_quantity' => $baseQuantity,
                    'purchase_order_line_id' => $purchaseOrderLine->getKey(),
                    'purchase_inbound_allocation_id' => $allocation->getKey(),
                ]);
            }

            return $operation->refresh()->load('lines');
        }, attempts: 5);
    }

    /**
     * @return list<array{purchase_inbound_allocation_id: int, quantity: string}>
     */
    private function deterministicRequests(PurchaseOrder $order): array
    {
        /** @var PurchaseInbound|null $inbound */
        $inbound = PurchaseInbound::query()
            ->where('purchase_order_id', $order->getKey())
            ->first();

        if (! $inbound instanceof PurchaseInbound) {
            throw PurchaseOrderNotAllocated::unallocated($order);
        }

        $allocations = PurchaseInboundAllocation::query()
            ->whereHas(
                'purchaseInboundLine',
                static fn ($query) => $query->where('purchase_inbound_id', $inbound->getKey()),
            )
            ->orderBy('id')
            ->get();

        if ($allocations->isEmpty()) {
            throw PurchaseOrderNotAllocated::unallocated($order);
        }

        $requests = [];

        foreach ($allocations as $allocation) {
            if ($allocation->allocated_base_quantity === null) {
                throw InvalidPurchaseInboundReceipt::unresolvedAllocationQuantity($allocation);
            }

            $requests[] = [
                'purchase_inbound_allocation_id' => (int) $allocation->getKey(),
                'quantity' => (string) $allocation->allocated_base_quantity,
            ];
        }

        return $requests;
    }

    /**
     * @param  list<array{purchase_inbound_allocation_id: int, quantity: string|int}>  $receiptLines
     * @return list<array{purchase_inbound_allocation_id: int, quantity: string}>
     */
    private function normalizeRequests(array $receiptLines): array
    {
        if ($receiptLines === []) {
            throw InvalidPurchaseInboundReceipt::quantityNotPositive();
        }

        $normalized = [];
        $seen = [];

        foreach ($receiptLines as $receiptLine) {
            $allocationId = $receiptLine['purchase_inbound_allocation_id'] ?? null;
            $quantity = $receiptLine['quantity'] ?? null;

            if (! is_int($allocationId) || $allocationId <= 0 || (! is_string($quantity) && ! is_int($quantity))) {
                throw InvalidPurchaseInboundReceipt::quantityNotPositive();
            }

            if (isset($seen[$allocationId])) {
                throw InvalidPurchaseInboundReceipt::duplicateAllocation($allocationId);
            }

            $seen[$allocationId] = true;
            $normalized[] = [
                'purchase_inbound_allocation_id' => $allocationId,
                'quantity' => $this->normalizeReceiptQuantity($quantity),
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => $left['purchase_inbound_allocation_id'] <=> $right['purchase_inbound_allocation_id'],
        );

        return $normalized;
    }

    /**
     * @param  list<array{purchase_inbound_allocation_id: int, quantity: string}>  $requests
     * @return list<array{
     *     allocation: PurchaseInboundAllocation,
     *     purchase_order_line: PurchaseOrderLine,
     *     warehouse: Warehouse,
     *     base_quantity: string,
     *     snapshot: NormalizedQuantity
     * }>
     */
    private function prepareReceiptLines(PurchaseOrder $order, array $requests, bool $legacyFallback): array
    {
        /** @var PurchaseInbound|null $inbound */
        $inbound = PurchaseInbound::query()
            ->where('purchase_order_id', $order->getKey())
            ->lockForUpdate()
            ->first();

        if (! $inbound instanceof PurchaseInbound) {
            throw PurchaseOrderNotAllocated::unallocated($order);
        }

        $allocationIds = array_column($requests, 'purchase_inbound_allocation_id');
        $allocationMetadata = PurchaseInboundAllocation::query()
            ->whereIn('id', $allocationIds)
            ->get(['id', 'purchase_inbound_line_id']);

        if ($allocationMetadata->count() !== count($allocationIds)) {
            throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
        }

        $inboundLineIds = $allocationMetadata
            ->pluck('purchase_inbound_line_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $inboundLines = PurchaseInboundLine::query()
            ->whereIn('id', $inboundLineIds)
            ->where('purchase_inbound_id', $inbound->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($inboundLines->count() !== $inboundLineIds->count()) {
            throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
        }

        $purchaseOrderLineIds = $inboundLines
            ->pluck('purchase_order_line_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $purchaseOrderLines = PurchaseOrderLine::query()
            ->whereIn('id', $purchaseOrderLineIds)
            ->where('purchase_order_id', $order->getKey())
            ->with('productVariant')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($purchaseOrderLines->count() !== $purchaseOrderLineIds->count()) {
            throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
        }

        $allocations = PurchaseInboundAllocation::query()
            ->whereIn('id', $allocationIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $warehouseIds = $allocations
            ->pluck('warehouse_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $warehouses = Warehouse::query()
            ->whereIn('id', $warehouseIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $prepared = [];

        foreach ($requests as $request) {
            /** @var PurchaseInboundAllocation|null $allocation */
            $allocation = $allocations->get($request['purchase_inbound_allocation_id']);

            if (! $allocation instanceof PurchaseInboundAllocation) {
                throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
            }

            /** @var PurchaseInboundLine|null $inboundLine */
            $inboundLine = $inboundLines->get($allocation->purchase_inbound_line_id);

            if (! $inboundLine instanceof PurchaseInboundLine) {
                throw InvalidPurchaseInboundReceipt::allocationNotForOrder($allocation, $order);
            }

            /** @var PurchaseOrderLine|null $purchaseOrderLine */
            $purchaseOrderLine = $purchaseOrderLines->get($inboundLine->purchase_order_line_id);

            if (! $purchaseOrderLine instanceof PurchaseOrderLine) {
                throw InvalidPurchaseInboundReceipt::allocationNotForOrder($allocation, $order);
            }

            /** @var Warehouse|null $warehouse */
            $warehouse = $warehouses->get($allocation->warehouse_id);

            if (! $warehouse instanceof Warehouse) {
                throw InvalidPurchaseInboundReceipt::missingAllocationProvenance();
            }

            $this->assertWarehouseIsUsable($warehouse);

            if ($allocation->allocated_base_quantity === null) {
                throw InvalidPurchaseInboundReceipt::unresolvedAllocationQuantity($allocation);
            }

            $snapshot = $this->snapshotFor($purchaseOrderLine);
            $allocationRemaining = $this->allocationAvailableForNewReceipt($allocation);
            $purchaseOrderRemaining = $this->purchaseOrderLineAvailableForNewReceipt($order, $purchaseOrderLine, $snapshot);
            $requested = $request['quantity'];

            if ($legacyFallback) {
                $requested = $this->minimumQuantity($requested, $allocationRemaining, $purchaseOrderRemaining);

                if (bccomp($requested, '0.000000', self::QUANTITY_SCALE) <= 0) {
                    continue;
                }
            } else {
                if (bccomp($requested, $allocationRemaining, self::QUANTITY_SCALE) === 1) {
                    throw InvalidPurchaseInboundReceipt::allocationExceeded($requested, $allocationRemaining);
                }

                if (bccomp($requested, $purchaseOrderRemaining, self::QUANTITY_SCALE) === 1) {
                    throw InvalidPurchaseInboundReceipt::purchaseOrderLineExceeded($requested, $purchaseOrderRemaining);
                }
            }

            $prepared[] = [
                'allocation' => $allocation,
                'purchase_order_line' => $purchaseOrderLine,
                'warehouse' => $warehouse,
                'base_quantity' => $requested,
                'snapshot' => $snapshot,
            ];
        }

        if ($prepared === []) {
            throw InvalidPurchaseInboundReceipt::nothingAvailable($order);
        }

        return $prepared;
    }

    private function snapshotFor(PurchaseOrderLine $line): NormalizedQuantity
    {
        $variant = $line->productVariant;

        if (
            $line->transaction_quantity !== null
            && $line->transaction_unit_id !== null
            && $line->conversion_factor_snapshot !== null
            && $line->base_quantity !== null
        ) {
            return new NormalizedQuantity(
                transactionQuantity: $line->transaction_quantity,
                transactionUnitId: $line->transaction_unit_id,
                conversionFactorSnapshot: $line->conversion_factor_snapshot,
                baseUnitId: $this->baseUnitId($variant),
                baseQuantity: $line->base_quantity,
            );
        }

        $snapshot = $this->quantityNormalizer->normalize($variant, $line->unit_id, (string) $line->quantity_ordered);
        $receivedBaseQuantity = $line->quantity_received === '0.000000'
            ? '0.000000'
            : $this->quantityNormalizer->normalize($variant, $line->unit_id, (string) $line->quantity_received)->baseQuantity;

        $line->forceFill([
            'transaction_quantity' => $snapshot->transactionQuantity,
            'transaction_unit_id' => $snapshot->transactionUnitId,
            'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
            'base_quantity' => $snapshot->baseQuantity,
            'received_base_quantity' => $receivedBaseQuantity,
        ])->save();

        return $snapshot;
    }

    private function allocationAvailableForNewReceipt(PurchaseInboundAllocation $allocation): string
    {
        if ($allocation->allocated_base_quantity === null) {
            throw InvalidPurchaseInboundReceipt::unresolvedAllocationQuantity($allocation);
        }

        $reserved = InventoryOperationLine::query()
            ->where('purchase_inbound_allocation_id', $allocation->getKey())
            ->whereNotNull('base_quantity')
            ->whereHas('operation', static fn ($query) => $query
                ->where('operation_type', OperationType::Receipt->value)
                ->where('stage', '!=', OperationStage::Canceled->value))
            ->sum('base_quantity');

        $remaining = bcsub(
            (string) $allocation->allocated_base_quantity,
            bcadd('0.000000', (string) $reserved, self::QUANTITY_SCALE),
            self::QUANTITY_SCALE,
        );

        return bccomp($remaining, '0.000000', self::QUANTITY_SCALE) === -1
            ? '0.000000'
            : $remaining;
    }

    private function purchaseOrderLineAvailableForNewReceipt(
        PurchaseOrder $order,
        PurchaseOrderLine $line,
        NormalizedQuantity $snapshot,
    ): string {
        $completedReceived = $line->received_base_quantity;

        if ($completedReceived === null) {
            $completedReceived = InventoryOperationLine::query()
                ->where('purchase_order_line_id', $line->getKey())
                ->whereNotNull('base_quantity')
                ->whereHas('operation', static fn ($query) => $query
                    ->where('operation_type', OperationType::Receipt->value)
                    ->where('source_document_type', PurchaseOrder::class)
                    ->where('source_document_id', $order->getKey())
                    ->where('stage', OperationStage::Done->value))
                ->sum('base_quantity');
        }

        $pending = InventoryOperationLine::query()
            ->where('purchase_order_line_id', $line->getKey())
            ->whereNotNull('base_quantity')
            ->whereHas('operation', static fn ($query) => $query
                ->where('operation_type', OperationType::Receipt->value)
                ->where('source_document_type', PurchaseOrder::class)
                ->where('source_document_id', $order->getKey())
                ->whereNotIn('stage', [OperationStage::Done->value, OperationStage::Canceled->value]))
            ->sum('base_quantity');

        $committed = bcadd(
            bcadd('0.000000', (string) $completedReceived, self::QUANTITY_SCALE),
            bcadd('0.000000', (string) $pending, self::QUANTITY_SCALE),
            self::QUANTITY_SCALE,
        );

        $remaining = bcsub(
            $snapshot->baseQuantity,
            $committed,
            self::QUANTITY_SCALE,
        );

        return bccomp($remaining, '0.000000', self::QUANTITY_SCALE) === -1
            ? '0.000000'
            : $remaining;
    }

    /** @return numeric-string */
    private function normalizeReceiptQuantity(string|int $quantity): string
    {
        $decimal = (string) $quantity;

        if (
            ! is_numeric($decimal)
            || preg_match('/^\d+(?:\.\d{1,6})?$/D', $decimal) !== 1
            || bccomp($decimal, '0', self::QUANTITY_SCALE) !== 1
        ) {
            throw InvalidPurchaseInboundReceipt::quantityNotPositive();
        }

        return bcadd($decimal, '0', self::QUANTITY_SCALE);
    }

    private function minimumQuantity(string ...$quantities): string
    {
        $minimum = array_shift($quantities) ?? '0.000000';

        foreach ($quantities as $quantity) {
            if (bccomp($quantity, $minimum, self::QUANTITY_SCALE) === -1) {
                $minimum = $quantity;
            }
        }

        return bcadd($minimum, '0', self::QUANTITY_SCALE);
    }

    private function baseUnitId(ProductVariant $variant): int
    {
        if (! is_int($variant->unit_id)) {
            throw new \LogicException('Purchase order variants require an integer base unit identifier.');
        }

        return $variant->unit_id;
    }

    private function assertWarehouseIsUsable(Warehouse $warehouse): void
    {
        if (! $warehouse->is_active || $warehouse->trashed()) {
            throw InvalidPurchaseInboundReceipt::inactiveWarehouse($warehouse);
        }
    }
}
