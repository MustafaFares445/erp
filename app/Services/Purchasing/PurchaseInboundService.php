<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\InventoryOperationLine;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\PurchaseReplenishmentCoverageService;
use App\Services\Purchasing\Exceptions\InvalidPurchaseInboundAllocation;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotAllocated;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class PurchaseInboundService
{
    private const int QUANTITY_SCALE = 6;

    public function __construct(
        private PurchaseReplenishmentCoverageService $replenishmentCoverage,
        private PurchaseInboundStatusService $statusService,
    ) {}

    public function ensureForAccepted(PurchaseOrder $order): PurchaseInbound
    {
        return DB::transaction(function () use ($order): PurchaseInbound {
            /** @var PurchaseInbound $inbound */
            $inbound = PurchaseInbound::query()->lockForUpdate()->firstOrCreate(
                ['purchase_order_id' => $order->id],
                ['activated_at' => now()],
            );

            $existingLineIds = $inbound->lines()->pluck('purchase_order_line_id')->all();

            foreach ($order->lines()->whereNotIn('id', $existingLineIds)->get() as $line) {
                $inbound->lines()->create(['purchase_order_line_id' => $line->id]);
            }

            return $inbound->refresh();
        });
    }

    /**
     * Create one warehouse quantity split for an inbound line.
     *
     * The nullable quantity is a temporary compatibility path for the existing
     * single-warehouse Filament action. With no quantity supplied, a line with
     * no allocation receives its full inbound base quantity; a line with one
     * existing allocation may be moved while it has no receipt commitments.
     * Once a line has multiple allocations callers must provide an explicit
     * quantity.
     *
     * @throws AuthorizationException
     * @throws InvalidPurchaseInboundAllocation
     */
    public function allocate(
        User $actor,
        PurchaseInboundLine $line,
        Warehouse $warehouse,
        string|int|null $allocatedBaseQuantity = null,
    ): PurchaseInboundAllocation {
        $this->authorizeAllocation($actor);

        return DB::transaction(function () use ($actor, $line, $warehouse, $allocatedBaseQuantity): PurchaseInboundAllocation {
            [$inbound, $lockedLine, $purchaseOrderLine] = $this->lockAllocationContext($line);
            $allocations = $this->lockAllocations($lockedLine);
            $this->assertWarehouseIsUsable($warehouse);

            if ($allocatedBaseQuantity === null) {
                $allocation = $this->allocateLegacyCompatible(
                    $actor,
                    $lockedLine,
                    $purchaseOrderLine,
                    $allocations,
                    $warehouse,
                );
            } else {
                $quantity = $this->normalizeAllocationQuantity($allocatedBaseQuantity);

                if ($this->allocationForWarehouse($allocations, $warehouse) instanceof PurchaseInboundAllocation) {
                    throw InvalidPurchaseInboundAllocation::duplicateWarehouse($warehouse);
                }

                $this->assertAllocationFits($lockedLine, $purchaseOrderLine, $allocations, $quantity);
                $allocation = $this->createAllocation($actor, $lockedLine, $warehouse, $quantity);
            }

            $this->afterAllocationMutation($inbound, $lockedLine);

            return $allocation->refresh();
        });
    }

    /**
     * Change a warehouse split without allowing the line total to exceed the
     * canonical PO base quantity or the allocation to fall below receipt
     * quantity already completed or reserved by an active receipt operation.
     *
     * Passing the inbound line explicitly makes cross-line/cross-inbound misuse
     * detectable rather than trusting an allocation id supplied by a caller.
     *
     * @throws AuthorizationException
     * @throws InvalidPurchaseInboundAllocation
     */
    public function updateAllocation(
        User $actor,
        PurchaseInboundLine $line,
        PurchaseInboundAllocation $allocation,
        Warehouse $warehouse,
        string|int $allocatedBaseQuantity,
    ): PurchaseInboundAllocation {
        $this->authorizeAllocation($actor);

        return DB::transaction(function () use ($actor, $line, $allocation, $warehouse, $allocatedBaseQuantity): PurchaseInboundAllocation {
            [$inbound, $lockedLine, $purchaseOrderLine] = $this->lockAllocationContext($line);
            $allocations = $this->lockAllocations($lockedLine);
            $lockedAllocation = $this->requireAllocationFromSet($allocation, $lockedLine, $allocations);
            $this->assertWarehouseIsUsable($warehouse);

            $quantity = $this->normalizeAllocationQuantity($allocatedBaseQuantity);
            $committed = $this->committedReceiptBaseQuantity($lockedAllocation);

            if (bccomp($quantity, $committed, self::QUANTITY_SCALE) === -1) {
                throw InvalidPurchaseInboundAllocation::belowCommitted($committed);
            }

            if (
                $lockedAllocation->warehouse_id !== $warehouse->id
                && bccomp($committed, '0.000000', self::QUANTITY_SCALE) === 1
            ) {
                throw InvalidPurchaseInboundAllocation::cannotMoveCommittedAllocation();
            }

            $sameWarehouse = $this->allocationForWarehouse($allocations, $warehouse, $lockedAllocation->id);

            if ($sameWarehouse instanceof PurchaseInboundAllocation) {
                throw InvalidPurchaseInboundAllocation::duplicateWarehouse($warehouse);
            }

            $this->assertAllocationFits(
                $lockedLine,
                $purchaseOrderLine,
                $allocations,
                $quantity,
                $lockedAllocation->id,
            );

            $lockedAllocation->forceFill([
                'warehouse_id' => $warehouse->id,
                'allocated_base_quantity' => $quantity,
                'updated_by' => $actor->id,
            ])->save();

            $this->afterAllocationMutation($inbound, $lockedLine);

            return $lockedAllocation->refresh();
        });
    }

    /**
     * Remove an unused allocation. Completed or active non-cancelled receipt
     * quantity makes the allocation provenance immutable.
     *
     * @throws AuthorizationException
     * @throws InvalidPurchaseInboundAllocation
     */
    public function removeAllocation(
        User $actor,
        PurchaseInboundLine $line,
        PurchaseInboundAllocation $allocation,
    ): void {
        $this->authorizeAllocation($actor);

        DB::transaction(function () use ($line, $allocation): void {
            [$inbound, $lockedLine] = $this->lockAllocationContext($line);
            $allocations = $this->lockAllocations($lockedLine);
            $lockedAllocation = $this->requireAllocationFromSet($allocation, $lockedLine, $allocations);
            $committed = $this->committedReceiptBaseQuantity($lockedAllocation);

            if (bccomp($committed, '0.000000', self::QUANTITY_SCALE) === 1) {
                throw InvalidPurchaseInboundAllocation::cannotDeleteCommitted($committed);
            }

            $lockedAllocation->delete();

            $this->afterAllocationMutation($inbound, $lockedLine);
        });
    }

    public function allocateAllTo(User $actor, PurchaseOrder $order, Warehouse $warehouse): PurchaseInbound
    {
        $this->authorizeAllocation($actor);
        $inbound = $this->ensureForAccepted($order);

        foreach ($inbound->lines as $line) {
            $this->allocate($actor, $line, $warehouse);
        }

        return $inbound->refresh();
    }

    /**
     * Temporary single-warehouse receiving compatibility.
     *
     * Allocation-aware receiving no longer relies on this method for canonical
     * multi-warehouse receipts. It remains for legacy callers that can only be
     * resolved to one distinct warehouse.
     *
     * @throws PurchaseOrderNotAllocated
     */
    public function resolveReceivingWarehouse(PurchaseOrder $order): Warehouse
    {
        $inbound = $order->purchaseInbound;

        if (! $inbound instanceof PurchaseInbound) {
            throw PurchaseOrderNotAllocated::unallocated($order);
        }

        $allocations = PurchaseInboundAllocation::query()
            ->whereHas(
                'purchaseInboundLine',
                static fn ($query) => $query->where('purchase_inbound_id', $inbound->id),
            )
            ->with('warehouse')
            ->get();

        /** @var Collection<int, Warehouse> $warehouses */
        $warehouses = $allocations
            ->map(static fn (PurchaseInboundAllocation $allocation): Warehouse => $allocation->warehouse)
            ->unique(static fn (Warehouse $warehouse): int => $warehouse->id)
            ->values();

        if ($warehouses->isEmpty()) {
            throw PurchaseOrderNotAllocated::unallocated($order);
        }

        if ($warehouses->count() > 1) {
            throw PurchaseOrderNotAllocated::ambiguous($order);
        }

        $warehouse = $warehouses->first();

        if (! $warehouse instanceof Warehouse) {
            throw PurchaseOrderNotAllocated::unallocated($order);
        }

        return $warehouse;
    }

    /**
     * @param  Collection<int, PurchaseInboundAllocation>  $allocations
     */
    private function allocateLegacyCompatible(
        User $actor,
        PurchaseInboundLine $line,
        PurchaseOrderLine $purchaseOrderLine,
        Collection $allocations,
        Warehouse $warehouse,
    ): PurchaseInboundAllocation {
        if ($allocations->isEmpty()) {
            $quantity = $this->inboundBaseQuantity($line, $purchaseOrderLine);

            return $this->createAllocation($actor, $line, $warehouse, $quantity);
        }

        if ($allocations->count() !== 1) {
            throw InvalidPurchaseInboundAllocation::quantityRequiredForSplit();
        }

        $existing = $allocations->first();

        if (! $existing instanceof PurchaseInboundAllocation) {
            throw InvalidPurchaseInboundAllocation::quantityRequiredForSplit();
        }

        $quantity = $existing->allocated_base_quantity ?? $this->inboundBaseQuantity($line, $purchaseOrderLine);

        if ($existing->warehouse_id === $warehouse->id) {
            if ($existing->allocated_base_quantity === null) {
                $existing->forceFill([
                    'allocated_base_quantity' => $quantity,
                    'updated_by' => $actor->id,
                ])->save();
            }

            return $existing;
        }

        $committed = $this->committedReceiptBaseQuantity($existing);

        if (bccomp($committed, '0.000000', self::QUANTITY_SCALE) === 1) {
            throw InvalidPurchaseInboundAllocation::cannotMoveCommittedAllocation();
        }

        $existing->forceFill([
            'warehouse_id' => $warehouse->id,
            'allocated_base_quantity' => $quantity,
            'updated_by' => $actor->id,
        ])->save();

        return $existing;
    }

    /** @param numeric-string $quantity */
    private function createAllocation(
        User $actor,
        PurchaseInboundLine $line,
        Warehouse $warehouse,
        string $quantity,
    ): PurchaseInboundAllocation {
        $allocation = new PurchaseInboundAllocation([
            'purchase_inbound_line_id' => $line->id,
            'warehouse_id' => $warehouse->id,
            'allocated_base_quantity' => $quantity,
        ]);

        $allocation->forceFill([
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();

        return $allocation;
    }

    /**
     * @return array{0: PurchaseInbound, 1: PurchaseInboundLine, 2: PurchaseOrderLine}
     */
    private function lockAllocationContext(PurchaseInboundLine $line): array
    {
        /** @var PurchaseInbound $inbound */
        $inbound = PurchaseInbound::query()
            ->lockForUpdate()
            ->findOrFail($line->purchase_inbound_id);

        /** @var PurchaseInboundLine $lockedLine */
        $lockedLine = PurchaseInboundLine::query()
            ->whereKey($line->id)
            ->where('purchase_inbound_id', $inbound->id)
            ->lockForUpdate()
            ->firstOrFail();

        /** @var PurchaseOrderLine $purchaseOrderLine */
        $purchaseOrderLine = PurchaseOrderLine::query()
            ->lockForUpdate()
            ->findOrFail($lockedLine->purchase_order_line_id);

        $lockedLine->setRelation('purchaseInbound', $inbound);
        $lockedLine->setRelation('purchaseOrderLine', $purchaseOrderLine);

        return [$inbound, $lockedLine, $purchaseOrderLine];
    }

    /** @return Collection<int, PurchaseInboundAllocation> */
    private function lockAllocations(PurchaseInboundLine $line): Collection
    {
        return PurchaseInboundAllocation::query()
            ->where('purchase_inbound_line_id', $line->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, PurchaseInboundAllocation>  $allocations
     */
    private function requireAllocationFromSet(
        PurchaseInboundAllocation $requested,
        PurchaseInboundLine $line,
        Collection $allocations,
    ): PurchaseInboundAllocation {
        foreach ($allocations as $allocation) {
            if ($allocation->id === $requested->id) {
                return $allocation;
            }
        }

        throw InvalidPurchaseInboundAllocation::wrongInboundLine($requested, $line);
    }

    /**
     * @param  Collection<int, PurchaseInboundAllocation>  $allocations
     */
    private function allocationForWarehouse(
        Collection $allocations,
        Warehouse $warehouse,
        ?int $exceptAllocationId = null,
    ): ?PurchaseInboundAllocation {
        foreach ($allocations as $allocation) {
            if ($exceptAllocationId !== null && $allocation->id === $exceptAllocationId) {
                continue;
            }

            if ($allocation->warehouse_id === $warehouse->id) {
                return $allocation;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, PurchaseInboundAllocation>  $allocations
     * @param  numeric-string  $candidateQuantity
     */
    private function assertAllocationFits(
        PurchaseInboundLine $line,
        PurchaseOrderLine $purchaseOrderLine,
        Collection $allocations,
        string $candidateQuantity,
        ?int $exceptAllocationId = null,
    ): void {
        $inboundQuantity = $this->inboundBaseQuantity($line, $purchaseOrderLine);
        /** @var numeric-string $total */
        $total = '0.000000';

        foreach ($allocations as $allocation) {
            if ($exceptAllocationId !== null && $allocation->id === $exceptAllocationId) {
                continue;
            }

            if ($allocation->allocated_base_quantity === null) {
                throw InvalidPurchaseInboundAllocation::unresolvedHistoricalQuantity($allocation);
            }

            $total = bcadd($total, $allocation->allocated_base_quantity, self::QUANTITY_SCALE);
        }

        $attemptedTotal = bcadd($total, $candidateQuantity, self::QUANTITY_SCALE);

        if (bccomp($attemptedTotal, $inboundQuantity, self::QUANTITY_SCALE) === 1) {
            throw InvalidPurchaseInboundAllocation::overAllocated($inboundQuantity, $attemptedTotal);
        }
    }

    /** @return numeric-string */
    private function inboundBaseQuantity(PurchaseInboundLine $line, PurchaseOrderLine $purchaseOrderLine): string
    {
        if ($purchaseOrderLine->base_quantity === null) {
            throw InvalidPurchaseInboundAllocation::inboundQuantityUnavailable($line);
        }

        return bcadd('0.000000', $purchaseOrderLine->base_quantity, self::QUANTITY_SCALE);
    }

    /** @return numeric-string */
    private function committedReceiptBaseQuantity(PurchaseInboundAllocation $allocation): string
    {
        $committed = InventoryOperationLine::query()
            ->where('purchase_inbound_allocation_id', $allocation->id)
            ->whereNotNull('base_quantity')
            ->whereHas('operation', static fn ($query) => $query
                ->where('operation_type', OperationType::Receipt->value)
                ->where('stage', '!=', OperationStage::Canceled->value))
            ->sum('base_quantity');

        if (! is_numeric($committed)) {
            return '0.000000';
        }

        /** @var numeric-string $committedQuantity */
        $committedQuantity = (string) $committed;

        return bcadd('0.000000', $committedQuantity, self::QUANTITY_SCALE);
    }

    /**
     * @return numeric-string
     * @throws InvalidPurchaseInboundAllocation
     */
    private function normalizeAllocationQuantity(string|int $quantity): string
    {
        $decimal = (string) $quantity;

        if (preg_match('/^\d+(?:\.\d{1,6})?$/', $decimal) !== 1 || ! is_numeric($decimal)) {
            throw InvalidPurchaseInboundAllocation::quantityNotPositive();
        }

        /** @var numeric-string $numericQuantity */
        $numericQuantity = $decimal;

        if (bccomp($numericQuantity, '0', self::QUANTITY_SCALE) !== 1) {
            throw InvalidPurchaseInboundAllocation::quantityNotPositive();
        }

        return bcadd($numericQuantity, '0', self::QUANTITY_SCALE);
    }

    /** @throws InvalidPurchaseInboundAllocation */
    private function assertWarehouseIsUsable(Warehouse $warehouse): void
    {
        $exists = Warehouse::query()
            ->whereKey($warehouse->id)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw InvalidPurchaseInboundAllocation::inactiveWarehouse($warehouse);
        }
    }

    /** @throws AuthorizationException */
    private function authorizeAllocation(User $actor): void
    {
        if (! $actor->can(InventoryPermission::InboundAllocate->value)) {
            throw new AuthorizationException('The actor is not authorized to allocate purchase inbound warehouse ownership.');
        }
    }

    private function afterAllocationMutation(PurchaseInbound $inbound, PurchaseInboundLine $line): void
    {
        $this->statusService->synchronize($inbound);
        $this->replenishmentCoverage->syncForInboundLine($line->refresh());
    }
}
