<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\MovementType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\TransferStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class StockTransferService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private InventoryAlertService $inventoryAlertService,
        private InventoryBalanceService $inventoryBalanceService,
    ) {}

    /** @throws DomainException */
    public function confirm(StockTransfer $transfer, User $actor): void
    {
        $this->dispatch($transfer, $actor);
    }

    /** @throws DomainException */
    public function dispatch(StockTransfer $transfer, User $actor): void
    {
        DB::transaction(function () use ($transfer, $actor): void {
            /** @var StockTransfer $locked */
            $locked = StockTransfer::query()->with(['fromWarehouse', 'toWarehouse'])->lockForUpdate()->findOrFail($transfer->getKey());

            if (! $locked->isDraft()) {
                throw new DomainException(__('admin.inventory.transfer.errors.not_draft'));
            }

            $this->assertWarehousesAreUsable($locked);
            /** @var Collection<int, StockTransferItem> $items */
            $items = $locked->items()->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new DomainException(__('admin.inventory.transfer.errors.no_items'));
            }

            $this->assertVariantsAreOperational($items, $locked->from_warehouse_id);
            $this->assertPackagesBelongToWarehouse($items, $locked->from_warehouse_id);
            $this->assertSufficientAvailability($items, $locked->from_warehouse_id);
            $balancesBefore = $this->currentBalances($items, $locked->from_warehouse_id, $locked->to_warehouse_id);

            foreach ($items as $item) {
                $stock = $this->applyOut($item, $locked->from_warehouse_id);
                $this->dispatchSerializedUnit($item, $locked->from_warehouse_id);
                $this->inventoryAlertService->syncStock($stock);
                $this->recordMovement($item, $locked, $locked->from_warehouse_id, -(float) $item->quantity, $actor, null);
            }

            $transferNumber = $locked->transfer_number ?? $this->nextTransferNumber();
            $locked->forceFill([
                'transfer_number' => $transferNumber,
                'status' => TransferStatus::Dispatched,
                'dispatched_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->saveQuietly();
            $this->inventoryAlertService->syncTransferDiscrepancy($locked);

            $this->auditLogger->log(
                action: 'inventory.transfer.dispatched',
                entity: $locked,
                oldValues: ['status' => TransferStatus::Draft->value, 'balances' => $balancesBefore],
                newValues: ['status' => TransferStatus::Dispatched->value, 'transfer_number' => $transferNumber],
                actor: $actor,
                sourceChannel: 'dashboard',
            );
        }, attempts: 5);
    }

    /** @throws DomainException */
    public function receive(StockTransfer $transfer, User $actor): void
    {
        DB::transaction(function () use ($transfer, $actor): void {
            /** @var StockTransfer $locked */
            $locked = StockTransfer::query()->with(['fromWarehouse', 'toWarehouse'])->lockForUpdate()->findOrFail($transfer->getKey());

            if (! $locked->isDispatched()) {
                throw new DomainException(__('admin.inventory.transfer.errors.not_dispatched'));
            }

            $this->assertWarehousesAreUsable($locked);
            /** @var Collection<int, StockTransferItem> $items */
            $items = $locked->items()->orderBy('id')->lockForUpdate()->get();
            $balancesBefore = $this->currentBalances($items, $locked->from_warehouse_id, $locked->to_warehouse_id);

            foreach ($items as $item) {
                if (! WarehouseLocation::belongsToWarehouse($item->warehouse_location_id, $locked->to_warehouse_id)) {
                    throw new DomainException(__('admin.inventory.transfer.errors.location_mismatch'));
                }

                $this->receiveSerializedUnit($item, $locked->to_warehouse_id);
                $stock = $this->applyIn($item, $locked->to_warehouse_id);
                $this->inventoryAlertService->syncStock($stock);
                $this->recordMovement($item, $locked, $locked->to_warehouse_id, (float) $item->quantity, $actor, $item->warehouse_location_id);
                $this->movePackageWithReceivedGoods($item, $locked->to_warehouse_id);
            }

            $locked->forceFill([
                'status' => TransferStatus::Received,
                'received_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->saveQuietly();
            $this->inventoryAlertService->syncTransferDiscrepancy($locked);

            $this->auditLogger->log(
                action: 'inventory.transfer.received',
                entity: $locked,
                oldValues: ['status' => TransferStatus::Dispatched->value, 'balances' => $balancesBefore],
                newValues: ['status' => TransferStatus::Received->value, 'balances' => $this->currentBalances($items, $locked->from_warehouse_id, $locked->to_warehouse_id)],
                actor: $actor,
                sourceChannel: 'dashboard',
            );
        }, attempts: 5);
    }

    /** @throws DomainException */
    private function assertWarehousesAreUsable(StockTransfer $transfer): void
    {
        if ($transfer->from_warehouse_id === $transfer->to_warehouse_id) {
            throw new DomainException(__('admin.inventory.transfer.errors.same_warehouse'));
        }

        $fromWarehouse = $transfer->fromWarehouse;
        $toWarehouse = $transfer->toWarehouse;

        if (! $fromWarehouse instanceof Warehouse || ! $fromWarehouse->is_active
            || ! $toWarehouse instanceof Warehouse || ! $toWarehouse->is_active) {
            throw new DomainException(__('admin.inventory.transfer.errors.inactive_warehouse'));
        }
    }

    /** @param Collection<int, StockTransferItem> $items @throws DomainException */
    private function assertSufficientAvailability(Collection $items, int $fromWarehouseId): void
    {
        foreach ($items->groupBy('product_variant_id') as $productVariantId => $lines) {
            $stock = InventoryStock::query()
                ->where('product_variant_id', $productVariantId)
                ->where('warehouse_id', $fromWarehouseId)
                ->lockForUpdate()
                ->first();
            $available = $stock instanceof InventoryStock ? (float) $stock->available_quantity : 0.0;

            if ($available < $this->decimal($lines->sum('quantity'))) {
                throw new DomainException(__('admin.inventory.transfer.errors.insufficient_stock'));
            }
        }
    }

    /** @param Collection<int, StockTransferItem> $items @throws DomainException */
    private function assertVariantsAreOperational(Collection $items, int $fromWarehouseId): void
    {
        foreach ($items->pluck('product_variant_id')->unique() as $productVariantId) {
            /** @var ProductVariant $variant */
            $variant = ProductVariant::query()->with('product')->lockForUpdate()->findOrFail($productVariantId);

            if (! $variant->isOperational()) {
                throw new DomainException(__('admin.inventory.transfer.errors.inactive_variant'));
            }

            if ($variant->track_serials) {
                foreach ($items->where('product_variant_id', $variant->getKey()) as $serialItem) {
                    if ($serialItem->serialized_inventory_unit_id === null || (float) $serialItem->quantity !== 1.0) {
                        throw new DomainException(__('admin.inventory.transfer.errors.serials_required'));
                    }

                    $serializedUnit = SerializedInventoryUnit::query()->lockForUpdate()->findOrFail($serialItem->serialized_inventory_unit_id);

                    if ($serializedUnit->product_variant_id !== $variant->getKey()
                        || $serializedUnit->warehouse_id !== $fromWarehouseId
                        || $serializedUnit->status !== SerializedInventoryUnitStatus::Available) {
                        throw new DomainException(__('admin.inventory.transfer.errors.invalid_serial'));
                    }
                }
            }
        }
    }

    /** @param Collection<int, StockTransferItem> $items @throws DomainException */
    private function assertPackagesBelongToWarehouse(Collection $items, int $warehouseId): void
    {
        foreach ($items as $item) {
            if (! Package::belongsToWarehouse($item->package_id, $warehouseId)) {
                throw new DomainException(__('admin.package.errors.location_mismatch'));
            }
        }
    }

    /** @throws DomainException */
    private function dispatchSerializedUnit(StockTransferItem $item, int $fromWarehouseId): void
    {
        if ($item->serialized_inventory_unit_id === null) {
            return;
        }

        /** @var SerializedInventoryUnit $serializedUnit */
        $serializedUnit = SerializedInventoryUnit::query()->lockForUpdate()->findOrFail($item->serialized_inventory_unit_id);

        if ($serializedUnit->warehouse_id !== $fromWarehouseId || $serializedUnit->status !== SerializedInventoryUnitStatus::Available) {
            throw new DomainException(__('admin.inventory.transfer.errors.invalid_serial'));
        }

        $serializedUnit->forceFill(['status' => SerializedInventoryUnitStatus::InTransit])->save();
    }

    /** @throws DomainException */
    private function receiveSerializedUnit(StockTransferItem $item, int $toWarehouseId): void
    {
        if ($item->serialized_inventory_unit_id === null) {
            return;
        }

        /** @var SerializedInventoryUnit $serializedUnit */
        $serializedUnit = SerializedInventoryUnit::query()->lockForUpdate()->findOrFail($item->serialized_inventory_unit_id);

        if ($serializedUnit->status !== SerializedInventoryUnitStatus::InTransit) {
            throw new DomainException(__('admin.inventory.transfer.errors.invalid_serial'));
        }

        $serializedUnit->forceFill([
            'warehouse_id' => $toWarehouseId,
            'warehouse_location_id' => $item->warehouse_location_id,
            'status' => SerializedInventoryUnitStatus::Available,
        ])->save();
    }

    private function applyOut(StockTransferItem $item, int $warehouseId): InventoryStock
    {
        return $this->inventoryBalanceService->transferOut(
            $item->product_variant_id,
            $warehouseId,
            (float) $item->quantity,
        );
    }

    private function movePackageWithReceivedGoods(StockTransferItem $item, int $warehouseId): void
    {
        if ($item->package_id === null) {
            return;
        }

        $package = Package::query()->lockForUpdate()->find($item->package_id);

        if ($package instanceof Package) {
            $package->moveWithRecordedGoods($warehouseId, $item->warehouse_location_id);
        }
    }

    private function applyIn(StockTransferItem $item, int $warehouseId): InventoryStock
    {
        return $this->inventoryBalanceService->transferIn(
            $item->product_variant_id,
            $warehouseId,
            (float) $item->quantity,
        );
    }

    private function recordMovement(StockTransferItem $item, StockTransfer $transfer, int $warehouseId, float $quantity, User $actor, ?int $locationId): void
    {
        InventoryMovement::query()->forceCreate(['product_variant_id' => $item->product_variant_id, 'warehouse_id' => $warehouseId, 'warehouse_location_id' => $locationId, 'movement_type' => MovementType::Transfer, 'quantity' => $quantity, 'source_type' => 'transfer', 'source_id' => $transfer->getKey(), 'serialized_inventory_unit_id' => $item->serialized_inventory_unit_id, 'package_id' => $item->package_id, 'status' => 'confirmed', 'created_by' => $actor->getKey(), 'notes' => $transfer->notes]);
    }

    /**
     * @param  Collection<int, StockTransferItem>  $items
     * @return array<int, array{from: float, to: float}>
     */
    private function currentBalances(Collection $items, int $fromWarehouseId, int $toWarehouseId): array
    {
        $balances = [];

        foreach ($items->pluck('product_variant_id')->unique() as $productVariantId) {
            if (! is_numeric($productVariantId)) {
                continue;
            }

            $balances[(int) $productVariantId] = [
                'from' => $this->decimal(InventoryStock::query()->where('product_variant_id', $productVariantId)->where('warehouse_id', $fromWarehouseId)->value('on_hand_quantity')),
                'to' => $this->decimal(InventoryStock::query()->where('product_variant_id', $productVariantId)->where('warehouse_id', $toWarehouseId)->value('on_hand_quantity')),
            ];
        }

        return $balances;
    }

    private function decimal(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new DomainException('Inventory quantities must be numeric.');
    }

    private function nextTransferNumber(): string
    {
        $maxNumber = StockTransfer::query()->whereNotNull('transfer_number')->lockForUpdate()->max('transfer_number');

        return sprintf('TRF-%06d', is_string($maxNumber) ? (int) mb_substr($maxNumber, 4) + 1 : 1);
    }
}
