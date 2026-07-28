<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\TransferStatus;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Verifies {@see InventoryOperationBackfiller} copied every legacy document faithfully
 * (data-model.md §10 step 5, R-002) — the mandatory gate before the backfill migration may be
 * considered safe (T026).
 *
 * Deliberately does not compare `inventory_stocks` or `inventory_movements` snapshots: the
 * backfiller structurally never writes to either table, so there is nothing there that could
 * drift. What this class verifies is that the copy itself is complete and faithful, and that the
 * in-transit total agrees under the old derivation (transfer `status = dispatched`) and the new
 * one (operation `stage = in_transit`) — the one thing that changed shape.
 *
 * @return list<string> is empty when reconciled; each entry is one concrete discrepancy.
 */
final readonly class OperationBackfillReconciler
{
    /**
     * @return list<string>
     */
    public function reconcile(): array
    {
        return [
            ...$this->reconcileReceipts(),
            ...$this->reconcileTransfers(),
            ...$this->reconcileInTransitTotals(),
        ];
    }

    /**
     * @return list<string>
     */
    private function reconcileReceipts(): array
    {
        $discrepancies = [];

        /** @var InventoryReceipt $receipt */
        foreach (InventoryReceipt::query()->with('items')->get() as $receipt) {
            $operation = InventoryOperation::query()->where('legacy_receipt_id', $receipt->id)->first();

            if (! $operation instanceof InventoryOperation) {
                $discrepancies[] = sprintf('Receipt #%d has no backfilled operation.', $receipt->id);

                continue;
            }

            if ($operation->destination_warehouse_id !== $receipt->warehouse_id) {
                $discrepancies[] = sprintf('Receipt #%d warehouse mismatch against operation #%d.', $receipt->id, $operation->id);
            }

            $legacyLineCount = $receipt->items->count();
            $operationLineCount = $operation->lines()->count();

            if ($legacyLineCount > 0 && $operationLineCount === 0) {
                $discrepancies[] = sprintf('Receipt #%d has %d legacy items but 0 backfilled lines.', $receipt->id, $legacyLineCount);
            }

            $legacyQuantity = $this->receiptQuantity($receipt);
            $operationQuantity = $this->operationQuantity($operation);

            if (abs($legacyQuantity - $operationQuantity) > 0.001) {
                $discrepancies[] = sprintf('Receipt #%d quantity %s does not match backfilled %s.', $receipt->id, $legacyQuantity, $operationQuantity);
            }
        }

        return $discrepancies;
    }

    /**
     * @return list<string>
     */
    private function reconcileTransfers(): array
    {
        $discrepancies = [];

        /** @var StockTransfer $transfer */
        foreach (StockTransfer::query()->with('items')->get() as $transfer) {
            $operation = InventoryOperation::query()->where('legacy_transfer_id', $transfer->id)->first();

            if (! $operation instanceof InventoryOperation) {
                $discrepancies[] = sprintf('Transfer #%d has no backfilled operation.', $transfer->id);

                continue;
            }

            if ($operation->source_warehouse_id !== $transfer->from_warehouse_id
                || $operation->destination_warehouse_id !== $transfer->to_warehouse_id) {
                $discrepancies[] = sprintf('Transfer #%d warehouse mismatch against operation #%d.', $transfer->id, $operation->id);
            }

            $legacyQuantity = $this->transferQuantity($transfer);
            $operationQuantity = $this->operationQuantity($operation);

            if (abs($legacyQuantity - $operationQuantity) > 0.001) {
                $discrepancies[] = sprintf('Transfer #%d quantity %s does not match backfilled %s.', $transfer->id, $legacyQuantity, $operationQuantity);
            }
        }

        return $discrepancies;
    }

    /**
     * @return list<string>
     */
    private function reconcileInTransitTotals(): array
    {
        $discrepancies = [];

        /** @var array<string, array{warehouse: int, variant: int}> $pairs */
        $pairs = [];

        foreach (StockTransferItem::query()
            ->whereHas('transfer', fn (Builder $query): Builder => $query->where('status', TransferStatus::Dispatched->value))
            ->with('transfer')
            ->get() as $transferItem) {
            $transfer = $transferItem->transfer;

            $pairs[sprintf('%d:%d', $transfer->to_warehouse_id, $transferItem->product_variant_id)] = [
                'warehouse' => $transfer->to_warehouse_id,
                'variant' => $transferItem->product_variant_id,
            ];
        }

        foreach ($pairs as $pair) {
            $legacyInTransit = StockTransferItem::query()
                ->where('product_variant_id', $pair['variant'])
                ->whereHas('transfer', fn (Builder $query): Builder => $query
                    ->where('to_warehouse_id', $pair['warehouse'])
                    ->where('status', TransferStatus::Dispatched->value))
                ->get()
                ->sum(fn (StockTransferItem $item): float => (float) $item->quantity);

            $backfilledInTransit = InventoryOperation::query()
                ->where('destination_warehouse_id', $pair['warehouse'])
                ->where('stage', 'in_transit')
                ->whereHas('lines', fn (Builder $query): Builder => $query->where('product_variant_id', $pair['variant']))
                ->with('lines')
                ->get()
                ->flatMap(fn (InventoryOperation $operation): Collection => $operation->lines)
                ->where('product_variant_id', $pair['variant'])
                ->sum(fn (InventoryOperationLine $line): float => (float) $line->quantity);

            if (abs($legacyInTransit - $backfilledInTransit) > 0.001) {
                $discrepancies[] = sprintf('In-transit total for variant %d at warehouse %d: legacy %s vs backfilled %s.', $pair['variant'], $pair['warehouse'], $legacyInTransit, $backfilledInTransit);
            }
        }

        return $discrepancies;
    }

    private function receiptQuantity(InventoryReceipt $receipt): float
    {
        return $receipt->items->sum(fn (InventoryReceiptItem $item): float => (float) $item->quantity);
    }

    private function transferQuantity(StockTransfer $transfer): float
    {
        return $transfer->items->sum(fn (StockTransferItem $item): float => (float) $item->quantity);
    }

    private function operationQuantity(InventoryOperation $operation): float
    {
        return $operation->lines->sum(fn (InventoryOperationLine $line): float => (float) $line->quantity);
    }
}
