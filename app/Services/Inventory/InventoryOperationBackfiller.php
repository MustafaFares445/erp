<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\ReceiptStatus;
use App\Enums\TransferStatus;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copies {@see InventoryReceipt} and {@see StockTransfer} documents into
 * {@see InventoryOperation}/{@see InventoryOperationLine} rows (data-model.md §10, R-002).
 *
 * Purely additive: never writes to a legacy table, never touches `inventory_stocks` or
 * `inventory_movements`. Idempotent — a row already backfilled (tracked by `legacy_receipt_id`
 * / `legacy_transfer_id`) is skipped, so this may run again after new legacy rows are created
 * during the dual-write window.
 */
final readonly class InventoryOperationBackfiller
{
    public function backfill(): void
    {
        DB::transaction(function (): void {
            $this->backfillReceipts();
            $this->backfillTransfers();
        });
    }

    private function backfillReceipts(): void
    {
        $alreadyBackfilled = InventoryOperation::query()->whereNotNull('legacy_receipt_id')->pluck('legacy_receipt_id')->all();

        InventoryReceipt::query()
            ->with('items.serializedUnits')
            ->whereNotIn('id', $alreadyBackfilled)
            ->chunkById(100, function (Collection $receipts): void {
                foreach ($receipts as $receipt) {
                    $operation = InventoryOperation::query()->forceCreate([
                        'operation_type' => OperationType::Receipt,
                        'stage' => $receipt->status === ReceiptStatus::Confirmed ? OperationStage::Done : OperationStage::Draft,
                        'operation_number' => $receipt->receipt_number,
                        'destination_warehouse_id' => $receipt->warehouse_id,
                        'supplier_id' => $receipt->supplier_id,
                        'supplier_reference' => $receipt->supplier_reference,
                        'notes' => $receipt->notes,
                        'completed_at' => $receipt->status === ReceiptStatus::Confirmed ? $receipt->updated_at : null,
                        'legacy_receipt_id' => $receipt->getKey(),
                        'created_by' => $receipt->created_by,
                        'updated_by' => $receipt->updated_by,
                        'created_at' => $receipt->created_at,
                        'updated_at' => $receipt->updated_at,
                    ]);

                    foreach ($receipt->items as $item) {
                        $this->backfillReceiptItem($operation, $item);
                    }
                }
            });
    }

    private function backfillReceiptItem(InventoryOperation $operation, InventoryReceiptItem $item): void
    {
        $unitId = $item->unit_id ?? $item->productVariant?->unit_id;

        if (! is_int($unitId)) {
            return;
        }

        $serializedUnits = $item->serializedUnits;

        if ($serializedUnits->isEmpty()) {
            InventoryOperationLine::query()->forceCreate([
                'inventory_operation_id' => $operation->getKey(),
                'product_variant_id' => $item->product_variant_id,
                'quantity' => $item->quantity,
                'unit_id' => $unitId,
                'unit_cost' => $item->purchase_cost,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ]);

            return;
        }

        foreach ($serializedUnits as $serializedUnit) {
            InventoryOperationLine::query()->forceCreate([
                'inventory_operation_id' => $operation->getKey(),
                'product_variant_id' => $item->product_variant_id,
                'quantity' => 1,
                'unit_id' => $unitId,
                'serialized_inventory_unit_id' => $serializedUnit->getKey(),
                'unit_cost' => $item->purchase_cost,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ]);
        }
    }

    private function backfillTransfers(): void
    {
        $alreadyBackfilled = InventoryOperation::query()->whereNotNull('legacy_transfer_id')->pluck('legacy_transfer_id')->all();

        StockTransfer::query()
            ->with('items')
            ->whereNotIn('id', $alreadyBackfilled)
            ->chunkById(100, function (Collection $transfers): void {
                foreach ($transfers as $transfer) {
                    $operation = InventoryOperation::query()->forceCreate([
                        'operation_type' => OperationType::InternalTransfer,
                        'stage' => match ($transfer->status) {
                            TransferStatus::Draft => OperationStage::Draft,
                            TransferStatus::Dispatched => OperationStage::InTransit,
                            TransferStatus::Received => OperationStage::Done,
                        },
                        'operation_number' => $transfer->transfer_number,
                        'source_warehouse_id' => $transfer->from_warehouse_id,
                        'destination_warehouse_id' => $transfer->to_warehouse_id,
                        'notes' => $transfer->notes,
                        'dispatched_at' => $transfer->dispatched_at,
                        'completed_at' => $transfer->received_at,
                        'legacy_transfer_id' => $transfer->getKey(),
                        'created_by' => $transfer->created_by,
                        'updated_by' => $transfer->updated_by,
                        'created_at' => $transfer->created_at,
                        'updated_at' => $transfer->updated_at,
                    ]);

                    foreach ($transfer->items as $item) {
                        $this->backfillTransferItem($operation, $item);
                    }
                }
            });
    }

    private function backfillTransferItem(InventoryOperation $operation, StockTransferItem $item): void
    {
        $unitId = $item->productVariant?->unit_id;

        if (! is_int($unitId)) {
            return;
        }

        InventoryOperationLine::query()->forceCreate([
            'inventory_operation_id' => $operation->getKey(),
            'product_variant_id' => $item->product_variant_id,
            'quantity' => $item->quantity,
            'unit_id' => $unitId,
            'serialized_inventory_unit_id' => $item->serialized_inventory_unit_id,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ]);
    }
}
