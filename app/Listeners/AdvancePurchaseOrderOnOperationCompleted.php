<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OperationType;
use App\Enums\PurchaseOrderStatus;
use App\Events\InventoryOperationCompleted;
use App\Models\InventoryOperation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\Purchasing\Exceptions\OverReceiptRejected;
use App\Services\Purchasing\SupplierCostWritebackService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Advances a purchase order's received quantities when a receipt against it
 * completes.
 *
 * Runs synchronously inside the completing transaction, so stock and received
 * quantity are consistent the moment it commits (R-002). It is **not** queued
 * on purpose: deferring would open a window in which stock exists but the order
 * shows nothing received, and a failed job would leave the two permanently
 * divergent.
 *
 * Over-receipt is rejected under a pessimistic row lock (R-003). Two concurrent
 * completions would otherwise both read a stale `quantity_received` and both
 * pass their own check — the exact FR-041 concurrency case. Throwing here rolls
 * the whole completion back, including the stock movement, which is the correct
 * outcome: the receipt was not legitimate.
 *
 * @see /specs/017-purchasing-orders-suppliers/research.md R-002, R-003
 */
final readonly class AdvancePurchaseOrderOnOperationCompleted
{
    public function __construct(private SupplierCostWritebackService $writeback) {}

    public function handle(InventoryOperationCompleted $event): void
    {
        $operation = $event->operation;

        $order = $this->purchaseOrderFor($operation);

        if (! $order instanceof PurchaseOrder) {
            return;
        }

        // Locked in id order, matching how InventoryOperationService already
        // locks its own lines, so two transactions cannot deadlock by taking the
        // same rows in opposite orders.
        /** @var Collection<int, PurchaseOrderLine> $lines */
        $lines = $order->lines()->with('productVariant')->orderBy('id')->lockForUpdate()->get();

        $incoming = $this->receivedQuantitiesByVariantAndUnit($operation);

        $this->assertNoOverReceipt($lines, $incoming);
        $this->applyReceipts($lines, $incoming);

        $this->advanceStatus($order, $event->actor);

        $this->writeback->apply($order, $lines, $incoming);
    }

    /**
     * The purchase order this operation received against, if any.
     *
     * Narrow by design: only a completed *receipt* whose source document is a
     * purchase order advances anything. A delivery, an internal transfer, or a
     * receipt raised outside purchasing passes straight through.
     */
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
     * Sums the operation's lines by `(variant, unit)`, which is the same key the
     * purchase order's unique index uses — so every received quantity has
     * exactly one line it can belong to.
     *
     * @return array<string, array{quantity: float, unit_cost: float|null}>
     */
    private function receivedQuantitiesByVariantAndUnit(InventoryOperation $operation): array
    {
        $totals = [];

        foreach ($operation->lines()->get() as $line) {
            $key = $line->product_variant_id.':'.$line->unit_id;
            $quantity = (float) $line->quantity;

            $totals[$key] ??= ['quantity' => 0.0, 'unit_cost' => null];
            $totals[$key]['quantity'] += $quantity;

            if ($line->unit_cost !== null) {
                // Last cost wins within one receipt. Averaging would need landed
                // cost, which this feature places out of scope (R-009).
                $totals[$key]['unit_cost'] = (float) $line->unit_cost;
            }
        }

        return $totals;
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @param  array<string, array{quantity: float, unit_cost: float|null}>  $incoming
     *
     * @throws OverReceiptRejected
     */
    private function assertNoOverReceipt(Collection $lines, array $incoming): void
    {
        foreach ($lines as $line) {
            $received = $incoming[$line->product_variant_id.':'.$line->unit_id]['quantity'] ?? 0.0;

            if ($received <= 0.0) {
                continue;
            }

            // Compared in thousandths, the precision the column stores, so a
            // legitimate exact-fill receipt is never rejected by float noise.
            $alreadyReceived = (int) round((float) $line->quantity_received * 1000);
            $ordered = (int) round((float) $line->quantity_ordered * 1000);
            $arriving = (int) round($received * 1000);

            if ($alreadyReceived + $arriving > $ordered) {
                throw OverReceiptRejected::forLine($line, $received);
            }
        }
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @param  array<string, array{quantity: float, unit_cost: float|null}>  $incoming
     */
    private function applyReceipts(Collection $lines, array $incoming): void
    {
        foreach ($lines as $line) {
            $entry = $incoming[$line->product_variant_id.':'.$line->unit_id] ?? null;
            if ($entry === null) {
                continue;
            }

            if ($entry['quantity'] <= 0.0) {
                continue;
            }

            $line->forceFill([
                'quantity_received' => round((float) $line->quantity_received + $entry['quantity'], 3),
                'last_received_unit_cost' => $entry['unit_cost'] !== null
                    ? round($entry['unit_cost'], 2)
                    : $line->last_received_unit_cost,
            ])->save();
        }
    }

    /**
     * Moves the order to `received` once every line is filled, or
     * `partially_received` while any remains outstanding.
     *
     * A short-closed or cancelled order is left alone: both are terminal, and
     * neither should be resurrected by a late receipt.
     */
    private function advanceStatus(PurchaseOrder $order, ?User $actor): void
    {
        // Reloaded once rather than refetched per line: applyReceipts() has
        // already saved the new quantities, so a fresh load of the relation is
        // the current truth and a per-line ->fresh() would be one query each.
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
