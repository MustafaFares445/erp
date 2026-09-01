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
    private const int QUANTITY_SCALE = 6;

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

        $incoming = $this->receivedQuantitiesByPurchaseOrderLine($operation);

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
     * Sums by origin purchase-order line. A receipt may select another permitted
     * transaction UOM, so matching variant and UOM would lose the commercial
     * line reference and let incompatible base quantities combine.
     *
     * @return array<int, array{base_quantity: numeric-string, transaction_unit_cost: float|null, base_unit_cost: float|null}>
     */
    private function receivedQuantitiesByPurchaseOrderLine(InventoryOperation $operation): array
    {
        $totals = [];

        foreach ($operation->lines()->get() as $line) {
            // A warehouse may add an unrelated received item to this physical receipt.
            // It remains a valid inventory posting, but it has no commercial PO line to
            // advance. A non-null origin, on the other hand, must be complete.
            if ($line->purchase_order_line_id === null) {
                continue;
            }

            if ($line->base_quantity === null) {
                throw new OverReceiptRejected('A purchase-order receipt line requires a base-quantity source reference.');
            }

            $key = $line->purchase_order_line_id;

            $totals[$key] ??= [
                'base_quantity' => '0.000000',
                'transaction_unit_cost' => null,
                'base_unit_cost' => null,
            ];
            $totals[$key]['base_quantity'] = bcadd($totals[$key]['base_quantity'], $line->base_quantity, self::QUANTITY_SCALE);

            if ($line->unit_cost !== null) {
                if (
                    $line->conversion_factor_snapshot === null
                    || bccomp($line->conversion_factor_snapshot, '0', self::QUANTITY_SCALE) <= 0
                ) {
                    throw new OverReceiptRejected('A costed purchase-order receipt line requires a positive conversion snapshot.');
                }

                // Last cost wins within one receipt. Keep both meanings explicit:
                // the receipt's transaction-UOM cost and the normalized base-UOM
                // cost used by inventory valuation and supplier references.
                $totals[$key]['transaction_unit_cost'] = (float) $line->unit_cost;
                $totals[$key]['base_unit_cost'] = (float) bcdiv(
                    (string) $line->unit_cost,
                    $line->conversion_factor_snapshot,
                    self::QUANTITY_SCALE,
                );
            }
        }

        return $totals;
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @param  array<int, array{base_quantity: numeric-string, transaction_unit_cost: float|null, base_unit_cost: float|null}>  $incoming
     *
     * @throws OverReceiptRejected
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
     * @param  array<int, array{base_quantity: numeric-string, transaction_unit_cost: float|null, base_unit_cost: float|null}>  $incoming
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
                'last_received_unit_cost' => $entry['base_unit_cost'] !== null
                    ? round($entry['base_unit_cost'] * (float) $line->conversion_factor_snapshot, 2)
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
