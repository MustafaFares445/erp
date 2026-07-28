<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryImportRowResult;
use App\Enums\InventoryImportItemStatus;
use App\Enums\InventoryImportRunStatus;
use App\Enums\ReceiptStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryImportItem;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class CatalogImportApplicationService
{
    public function __construct(
        private CatalogImportValidator $validator,
        private InventoryReceivingService $inventoryReceivingService,
        private CatalogImportCatalogService $catalogService,
        private AuditLogger $auditLogger,
    ) {}

    public function apply(InventoryImportRun $run, User $actor): void
    {
        $run = $run->fresh();

        if (! $run instanceof InventoryImportRun || $run->status !== InventoryImportRunStatus::Applying) {
            return;
        }

        /** @var Collection<int, InventoryImportItem> $items */
        $items = $run->items()
            ->where('status', InventoryImportItemStatus::Valid->value)
            ->orderBy('row_number')
            ->get();

        $catalogItems = $items->filter(fn (InventoryImportItem $item): bool => ! $this->isInventoryItem($item));
        $inventoryItems = $items->filter(fn (InventoryImportItem $item): bool => $this->isInventoryItem($item));

        $catalogItems->each(fn (InventoryImportItem $item) => $this->applyCatalogItem($item, $actor));

        $inventoryItems
            ->groupBy(fn (InventoryImportItem $item): string => $this->groupKey($item))
            ->each(fn (Collection $group) => $this->applyInventoryGroup($group, $actor));

        $this->finishRun($run, $actor);
    }

    private function applyCatalogItem(InventoryImportItem $item, User $actor): void
    {
        try {
            DB::transaction(function () use ($item, $actor): void {
                $locked = $this->lockValidItem($item);

                if (! $locked instanceof InventoryImportItem) {
                    return;
                }

                $locked->forceFill(['status' => InventoryImportItemStatus::Applying])->save();
                [$variant, $result] = $this->catalogService->apply($this->payload($locked), $actor);
                $this->markApplied($locked, $result, $result->catalogOperation ?? 'catalog_updated');
            }, attempts: 5);
        } catch (Throwable $throwable) {
            $this->markRejected([$this->itemId($item)], $throwable);
        }
    }

    /** @param Collection<int, InventoryImportItem> $items */
    private function applyInventoryGroup(Collection $items, User $actor): void
    {
        $ids = [];

        foreach ($items as $item) {
            $ids[] = $this->itemId($item);
        }

        try {
            DB::transaction(function () use ($ids, $actor): void {
                /** @var Collection<int, InventoryImportItem> $lockedItems */
                $lockedItems = InventoryImportItem::query()
                    ->whereKey($ids)
                    ->where('status', InventoryImportItemStatus::Valid->value)
                    ->orderBy('row_number')
                    ->lockForUpdate()
                    ->get();

                if ($lockedItems->isEmpty()) {
                    return;
                }

                InventoryImportItem::query()
                    ->whereKey($ids)
                    ->update(['status' => InventoryImportItemStatus::Applying->value]);
                $this->receiveGroup($lockedItems, $actor);
            }, attempts: 5);
        } catch (Throwable $throwable) {
            $this->markRejected($ids, $throwable);
        }
    }

    /** @param Collection<int, InventoryImportItem> $items */
    private function receiveGroup(Collection $items, User $actor): void
    {
        $firstPayload = $this->payload($items->firstOrFail());
        $warehouse = $this->resolveWarehouse($firstPayload['warehouse_code']);
        $supplier = $this->catalogService->resolveSupplier($firstPayload);
        $receipt = $this->createReceipt($items->firstOrFail(), $warehouse, $supplier, $actor);
        $results = [];

        foreach ($items as $item) {
            [$variant, $result] = $this->catalogService->apply($this->payload($item), $actor);
            $results[$this->itemId($item)] = $this->createReceiptItem($item, $receipt, $variant, $result);
        }

        $this->inventoryReceivingService->confirm($receipt, $actor);

        foreach ($items as $item) {
            $result = $results[$this->itemId($item)];
            $this->completeInventoryResult($result);
            $this->markApplied($item, $result, 'inventory_received');
        }
    }

    private function createReceipt(
        InventoryImportItem $item,
        Warehouse $warehouse,
        ?Supplier $supplier,
        User $actor,
    ): InventoryReceipt {
        return InventoryReceipt::query()->forceCreate([
            'warehouse_id' => $warehouse->getKey(),
            'supplier_id' => $supplier?->getKey(),
            'supplier_reference' => 'IMPORT-'.$item->inventory_import_run_id,
            'notes' => 'Catalog import run '.$item->inventory_import_run_id,
            'status' => ReceiptStatus::Draft,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);
    }

    private function createReceiptItem(
        InventoryImportItem $item,
        InventoryReceipt $receipt,
        ProductVariant $variant,
        InventoryImportRowResult $result,
    ): InventoryImportRowResult {
        $payload = $this->payload($item);
        $receiptItem = InventoryReceiptItem::query()->forceCreate([
            'inventory_receipt_id' => $receipt->getKey(),
            'product_variant_id' => $variant->getKey(),
            'unit_id' => $variant->unit_id,
            'quantity' => (float) $payload['quantity'],
            'purchase_cost' => isset($payload['cost_price']) ? (float) $payload['cost_price'] : null,
            'currency_code' => $payload['currency_code'] ?? 'USD',
            'expires_at' => $payload['expires_at'] ?? null,
            'lot_number' => $payload['lot_number'] ?? null,
        ]);

        $result->inventoryReceiptId = $this->integerKey($receipt->getKey());
        $result->inventoryReceiptItemId = $this->integerKey($receiptItem->getKey());

        if ($variant->track_serials) {
            $serializedUnit = SerializedInventoryUnit::query()->forceCreate([
                'product_variant_id' => $variant->getKey(),
                'warehouse_id' => null,
                'inventory_receipt_item_id' => $receiptItem->getKey(),
                'serial_number' => $payload['serial_number'],
                'iot_number' => $payload['iot_number'] ?? null,
                'status' => SerializedInventoryUnitStatus::Pending,
            ]);
            $result->serializedInventoryUnitId = $this->integerKey($serializedUnit->getKey());
        }

        return $result;
    }

    private function completeInventoryResult(InventoryImportRowResult $result): void
    {
        if ($result->inventoryReceiptItemId === null) {
            return;
        }

        $lotKey = InventoryLot::query()
            ->where('inventory_receipt_item_id', $result->inventoryReceiptItemId)
            ->value('id');

        if (is_int($lotKey)) {
            $result->inventoryLotId = $lotKey;
        }
    }

    private function markApplied(
        InventoryImportItem $item,
        InventoryImportRowResult $result,
        string $operation,
    ): void {
        $item->forceFill([
            'status' => InventoryImportItemStatus::Applied,
            'operation' => $operation,
            'runtime_error' => null,
            'result' => $result->values(),
            'applied_at' => now(),
        ])->save();
    }

    /** @param list<int> $ids */
    private function markRejected(array $ids, Throwable $throwable): void
    {
        InventoryImportItem::query()
            ->whereKey($ids)
            ->where('status', InventoryImportItemStatus::Valid->value)
            ->update([
                'status' => InventoryImportItemStatus::Rejected->value,
                'runtime_error' => Str::limit($throwable->getMessage(), 2_000),
                'updated_at' => now(),
            ]);
    }

    private function finishRun(InventoryImportRun $run, User $actor): void
    {
        DB::transaction(function () use ($run, $actor): void {
            /** @var InventoryImportRun $locked */
            $locked = InventoryImportRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($locked->status !== InventoryImportRunStatus::Applying) {
                return;
            }

            $outcomes = $this->outcomeCounters($locked);
            $status = $outcomes['rejected_rows'] === 0
                ? InventoryImportRunStatus::Confirmed
                : InventoryImportRunStatus::ConfirmedWithErrors;

            $locked->forceFill([
                ...$outcomes,
                'failed_rows' => $outcomes['rejected_rows'],
                'status' => $status,
                'confirmed_at' => now(),
            ])->save();

            $this->auditLogger->log(
                action: 'catalog.import.confirmed',
                entity: $locked,
                oldValues: ['status' => InventoryImportRunStatus::Applying->value],
                newValues: ['status' => $status->value, ...$outcomes],
                actor: $actor,
                sourceChannel: 'dashboard',
            );
        }, attempts: 5);

    }

    /** @return array{created_rows: int, updated_rows: int, applied_rows: int, rejected_rows: int} */
    private function outcomeCounters(InventoryImportRun $run): array
    {
        /** @var Collection<int, InventoryImportItem> $appliedItems */
        $appliedItems = $run->items()
            ->where('status', InventoryImportItemStatus::Applied->value)
            ->get(['result']);

        return [
            'created_rows' => $appliedItems->filter(fn (InventoryImportItem $item): bool => ($item->result['catalog_operation'] ?? null) === 'catalog_created')->count(),
            'updated_rows' => $appliedItems->filter(fn (InventoryImportItem $item): bool => ($item->result['catalog_operation'] ?? null) === 'catalog_updated')->count(),
            'applied_rows' => $appliedItems->count(),
            'rejected_rows' => $run->items()->whereIn('status', [
                InventoryImportItemStatus::Invalid->value,
                InventoryImportItemStatus::Rejected->value,
            ])->count(),
        ];
    }

    private function lockValidItem(InventoryImportItem $item): ?InventoryImportItem
    {
        return InventoryImportItem::query()
            ->whereKey($item->getKey())
            ->where('status', InventoryImportItemStatus::Valid->value)
            ->lockForUpdate()
            ->first();
    }

    private function resolveWarehouse(string $code): Warehouse
    {
        return Warehouse::query()
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function isInventoryItem(InventoryImportItem $item): bool
    {
        return $this->validator->hasInventoryData($this->payload($item));
    }

    private function groupKey(InventoryImportItem $item): string
    {
        $payload = $this->payload($item);
        $supplier = $payload['supplier_code']
            ?? (isset($payload['supplier_name']) ? Str::upper(Str::slug($payload['supplier_name'])) : '');

        return mb_strtolower($payload['warehouse_code'].'|'.$supplier);
    }

    /** @return array<string, string> */
    private function payload(InventoryImportItem $item): array
    {
        /** @var array<string, string> $payload */
        $payload = $item->payload;

        return $payload;
    }

    private function itemId(InventoryImportItem $item): int
    {
        return $this->integerKey($item->getKey());
    }

    private function integerKey(mixed $key): int
    {
        if (! is_int($key)) {
            throw new \LogicException('Imported inventory entities must use integer identifiers.');
        }

        return $key;
    }
}
