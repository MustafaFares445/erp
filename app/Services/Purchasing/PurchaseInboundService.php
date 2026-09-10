<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\PurchaseInboundStatus;
use App\Listeners\AdvancePurchaseOrderOnOperationCompleted;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotAllocated;
use Illuminate\Support\Facades\DB;

/**
 * Owns the warehouse-allocation aggregate created once a purchase order is
 * accepted (Phase 0 remediation).
 *
 * `ensureForAccepted()` follows the same idempotent, lock-then-create-if-missing
 * shape as {@see AdvancePurchaseOrderOnOperationCompleted}: it
 * is safe to call every time an order is (re-)accepted, and safe to call before
 * every receipt, because retrying never produces a second inbound or a second
 * line for the same order/line pair — the unique indexes on
 * `purchase_inbounds.purchase_order_id` and
 * `purchase_inbound_lines.purchase_order_line_id` make a duplicate a
 * constraint violation, not a silent one.
 */
final readonly class PurchaseInboundService
{
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

    public function allocate(User $actor, PurchaseInboundLine $line, Warehouse $warehouse): PurchaseInboundAllocation
    {
        return DB::transaction(function () use ($actor, $line, $warehouse): PurchaseInboundAllocation {
            $allocation = PurchaseInboundAllocation::query()->updateOrCreate(
                ['purchase_inbound_line_id' => $line->getKey()],
                ['warehouse_id' => $warehouse->getKey(), 'updated_by' => $actor->getKey()],
            );

            $this->advanceAllocationStatus($line->purchaseInbound()->firstOrFail());

            return $allocation;
        });
    }

    /** Allocates every line of the order's inbound to the same warehouse. */
    public function allocateAllTo(User $actor, PurchaseOrder $order, Warehouse $warehouse): PurchaseInbound
    {
        $inbound = $this->ensureForAccepted($order);

        foreach ($inbound->lines as $line) {
            $this->allocate($actor, $line, $warehouse);
        }

        return $inbound->refresh();
    }

    /**
     * The single warehouse a receipt against this order should target.
     *
     * A purchase order no longer owns a warehouse (Phase 0), so
     * {@see PurchaseOrderReceivingService} resolves one here instead: every
     * outstanding line's allocation must agree, because one receipt targets
     * one warehouse.
     *
     * @throws PurchaseOrderNotAllocated
     */
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
