<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\InventoryPermission;
use App\Enums\PurchaseInboundStatus;
use App\Listeners\AdvancePurchaseOrderOnOperationCompleted;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\PurchaseReplenishmentCoverageService;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotAllocated;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class PurchaseInboundService
{
    public function __construct(private PurchaseReplenishmentCoverageService $replenishmentCoverage) {}

    public function ensureForAccepted(PurchaseOrder $order): PurchaseInbound
    {
        return DB::transaction(function () use ($order): PurchaseInbound {
            /** @var PurchaseInbound $inbound */
            $inbound = PurchaseInbound::query()->lockForUpdate()->firstOrCreate(
                ['purchase_order_id' => $order->getKey()],
                ['activated_at' => now()],
            );

            $existingLineIds = $inbound->lines()->pluck('purchase_order_line_id')->all();

            foreach ($order->lines()->whereNotIn('id', $existingLineIds)->get() as $line) {
                $inbound->lines()->create(['purchase_order_line_id' => $line->getKey()]);
            }

            return $inbound->refresh();
        });
    }

    /** @throws AuthorizationException */
    public function allocate(User $actor, PurchaseInboundLine $line, Warehouse $warehouse): PurchaseInboundAllocation
    {
        $this->authorizeAllocation($actor);

        return DB::transaction(function () use ($actor, $line, $warehouse): PurchaseInboundAllocation {
            $allocation = PurchaseInboundAllocation::query()->updateOrCreate(
                ['purchase_inbound_line_id' => $line->getKey()],
                ['warehouse_id' => $warehouse->getKey(), 'updated_by' => $actor->getKey()],
            );

            $this->advanceAllocationStatus($line->purchaseInbound()->firstOrFail());
            $this->replenishmentCoverage->syncForInboundLine($line->refresh());

            return $allocation->refresh();
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

    /** @throws PurchaseOrderNotAllocated */
    public function resolveReceivingWarehouse(PurchaseOrder $order): Warehouse
    {
        $inbound = $order->purchaseInbound;

        $warehouses = $inbound instanceof PurchaseInbound
            ? $inbound->lines()->with('allocation.warehouse')->get()
                ->map(fn (PurchaseInboundLine $line): ?Warehouse => $line->allocation?->warehouse)
                ->filter()
                ->unique(fn (Warehouse $warehouse): int => $warehouse->getKey())
            : collect();

        if ($warehouses->isEmpty()) {
            throw PurchaseOrderNotAllocated::unallocated($order);
        }

        if ($warehouses->count() > 1) {
            throw PurchaseOrderNotAllocated::ambiguous($order);
        }

        return $warehouses->first();
    }

    /** @throws AuthorizationException */
    private function authorizeAllocation(User $actor): void
    {
        if (!$actor->can(InventoryPermission::InboundAllocate->value)) {
            throw new AuthorizationException('The actor is not authorized to allocate purchase inbound warehouse ownership.');
        }
    }

    private function advanceAllocationStatus(PurchaseInbound $inbound): void
    {
        $inbound->load('lines.allocation');

        $target = $inbound->lines->every(fn (PurchaseInboundLine $line): bool => $line->allocation !== null)
            ? PurchaseInboundStatus::AwaitingReceipt
            : PurchaseInboundStatus::AwaitingAllocation;

        if ($inbound->status === $target) {
            return;
        }

        $inbound->forceFill([
            'status' => $target,
            'allocation_confirmed_at' => $target === PurchaseInboundStatus::AwaitingReceipt ? now() : null,
        ])->save();
    }
}
