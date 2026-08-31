<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\OperationType;
use App\Models\InventoryOperation;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Translates retained legacy receipt rows into a canonical receipt operation.
 *
 * This is a bounded Phase 3 data-transition adapter, not a second receiving
 * workflow: it delegates every balance and ledger write to
 * {@see InventoryOperationService}. Delete it with legacy receipt data in
 * Phase 10 after reconciliation has completed.
 */
final readonly class LegacyReceiptOperationConverter
{
    public function __construct(private InventoryOperationService $inventoryOperationService) {}

    public function complete(InventoryReceipt $receipt, User $actor): InventoryOperation
    {
        return DB::transaction(function () use ($receipt, $actor): InventoryOperation {
            $legacyReceipt = InventoryReceipt::query()
                ->with(['items.productVariant', 'items.serializedUnits'])
                ->lockForUpdate()
                ->findOrFail($this->receiptId($receipt));

            $existingOperation = InventoryOperation::query()
                ->where('source_document_type', InventoryReceipt::class)
                ->where('source_document_id', $legacyReceipt->getKey())
                ->lockForUpdate()
                ->first();

            if ($existingOperation instanceof InventoryOperation) {
                return $existingOperation;
            }

            $operation = InventoryOperation::query()->forceCreate([
                'operation_type' => OperationType::Receipt,
                'destination_warehouse_id' => $legacyReceipt->warehouse_id,
                'supplier_id' => $legacyReceipt->supplier_id,
                'source_document_type' => InventoryReceipt::class,
                'source_document_id' => $legacyReceipt->getKey(),
                'supplier_reference' => $legacyReceipt->supplier_reference,
                'notes' => $legacyReceipt->notes,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            /** @var Collection<int, InventoryReceiptItem> $items */
            $items = $legacyReceipt->items;

            foreach ($items as $item) {
                $this->addItemLines($operation, $item);
            }

            $this->inventoryOperationService->markReady($operation, $actor);

            return $this->inventoryOperationService->complete($operation->refresh(), $actor);
        }, attempts: 5);
    }

    private function addItemLines(InventoryOperation $operation, InventoryReceiptItem $item): void
    {
        /** @var Collection<int, SerializedInventoryUnit> $serializedUnits */
        $serializedUnits = $item->serializedUnits;

        if ($serializedUnits->isEmpty()) {
            $this->addOperationLine($operation, $item, null);

            return;
        }

        foreach ($serializedUnits as $serializedUnit) {
            $this->addOperationLine($operation, $item, $serializedUnit);
        }
    }

    private function addOperationLine(
        InventoryOperation $operation,
        InventoryReceiptItem $item,
        ?SerializedInventoryUnit $serializedUnit,
    ): void {
        $variant = $item->productVariant;

        if (! $variant instanceof ProductVariant) {
            throw new \LogicException('Legacy receipt items require a product variant.');
        }

        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'unit_id' => $item->unit_id ?? $variant->unit_id,
            'quantity' => $serializedUnit instanceof SerializedInventoryUnit ? '1' : (string) $item->quantity,
            'unit_cost' => $item->purchase_cost,
            'lot_number' => $item->lot_number,
            'expires_at' => $item->expires_at,
            'serialized_inventory_unit_id' => $serializedUnit?->getKey(),
        ]);
    }

    private function receiptId(InventoryReceipt $receipt): int
    {
        $key = $receipt->getKey();

        if (! is_int($key)) {
            throw new \LogicException('Legacy receipts must use integer identifiers.');
        }

        return $key;
    }
}
