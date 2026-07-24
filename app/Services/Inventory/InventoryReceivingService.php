<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\MovementType;
use App\Enums\ReceiptStatus;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class InventoryReceivingService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private ProductPricingService $productPricingService,
        private InventoryAlertService $inventoryAlertService,
    ) {}

    /** @throws DomainException */
    public function confirm(InventoryReceipt $receipt, User $actor): void
    {
        DB::transaction(function () use ($receipt, $actor): void {
            /** @var InventoryReceipt $locked */
            $locked = InventoryReceipt::query()->with('warehouse')->lockForUpdate()->findOrFail($receipt->getKey());

            if (! $locked->isDraft()) {
                throw new DomainException(__('admin.inventory.receipt.errors.not_draft'));
            }

            if (! $locked->warehouse instanceof Warehouse || ! $locked->warehouse->is_active) {
                throw new DomainException(__('admin.inventory.receipt.errors.inactive_warehouse'));
            }

            $items = $locked->items()->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new DomainException(__('admin.inventory.receipt.errors.no_items'));
            }

            foreach ($items as $item) {
                $this->receiveItem($item, $locked, $actor);
            }

            $receiptNumber = $locked->receipt_number ?? $this->nextReceiptNumber();
            $locked->forceFill([
                'receipt_number' => $receiptNumber,
                'status' => ReceiptStatus::Confirmed,
                'updated_by' => $actor->getKey(),
            ])->saveQuietly();

            $this->auditLogger->log(
                action: 'inventory.receipt.confirmed',
                entity: $locked,
                oldValues: ['status' => ReceiptStatus::Draft->value],
                newValues: ['status' => ReceiptStatus::Confirmed->value, 'receipt_number' => $receiptNumber],
                actor: $actor,
                sourceChannel: 'dashboard',
            );
        }, attempts: 5);
    }

    /** @throws DomainException */
    private function receiveItem(InventoryReceiptItem $item, InventoryReceipt $receipt, User $actor): void
    {
        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()->with(['product', 'unit'])->lockForUpdate()->findOrFail($item->product_variant_id);

        if (! $variant->isOperational()) {
            throw new DomainException(__('admin.inventory.receipt.errors.inactive_variant'));
        }

        if ((float) $item->quantity <= 0) {
            throw new DomainException(__('admin.inventory.receipt.errors.invalid_quantity'));
        }

        if ($item->unit?->allows_decimal === false && fmod((float) $item->quantity, 1.0) !== 0.0) {
            throw new DomainException(__('admin.inventory.receipt.errors.invalid_unit_quantity'));
        }

        if ($variant->track_serials) {
            $this->assignSerializedUnits($item, $receipt, $variant);
        }

        $lot = $variant->track_expiry ? $this->recordLot($item, $receipt, $variant) : null;
        $stock = $this->increaseStock($variant, $receipt, (float) $item->quantity);
        $this->inventoryAlertService->syncStock($stock);

        if ($lot instanceof InventoryLot) {
            $this->inventoryAlertService->syncExpiry($lot);
        }

        InventoryMovement::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $receipt->warehouse_id,
            'movement_type' => MovementType::Receipt,
            'quantity' => (float) $item->quantity,
            'source_type' => 'receipt',
            'source_id' => $receipt->getKey(),
            'inventory_receipt_item_id' => $item->getKey(),
            'inventory_lot_id' => $lot?->getKey(),
            'status' => 'confirmed',
            'created_by' => $actor->getKey(),
            'notes' => $receipt->supplier_reference,
        ]);

        if ($item->purchase_cost !== null) {
            $this->productPricingService->updateCostFromInventory($variant, (float) $item->purchase_cost, $actor);
        }

        $this->auditLogger->log(
            action: 'inventory.receipt.item_received',
            entity: $item,
            oldValues: ['on_hand_quantity' => (float) $stock->on_hand_quantity - (float) $item->quantity],
            newValues: ['on_hand_quantity' => (float) $stock->on_hand_quantity],
            actor: $actor,
            sourceChannel: 'dashboard',
        );
    }

    /** @throws DomainException */
    private function assignSerializedUnits(InventoryReceiptItem $item, InventoryReceipt $receipt, ProductVariant $variant): void
    {
        if (fmod((float) $item->quantity, 1.0) !== 0.0) {
            throw new DomainException(__('admin.inventory.receipt.errors.serial_quantity_must_be_whole'));
        }

        $serializedUnits = $item->serializedUnits()->lockForUpdate()->get();

        if ($serializedUnits->count() !== (int) $item->quantity) {
            throw new DomainException(__('admin.inventory.receipt.errors.serials_required'));
        }

        foreach ($serializedUnits as $serializedUnit) {
            if ($serializedUnit->product_variant_id !== $variant->getKey()) {
                throw new DomainException(__('admin.inventory.receipt.errors.serial_variant_mismatch'));
            }

            $serializedUnit->forceFill([
                'warehouse_id' => $receipt->warehouse_id,
                'status' => 'available',
            ])->save();
        }
    }

    /** @throws DomainException */
    private function recordLot(InventoryReceiptItem $item, InventoryReceipt $receipt, ProductVariant $variant): InventoryLot
    {
        if ($item->expires_at === null) {
            throw new DomainException(__('admin.inventory.receipt.errors.expiry_required'));
        }

        return InventoryLot::query()->create([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $receipt->warehouse_id,
            'inventory_receipt_item_id' => $item->getKey(),
            'lot_number' => $item->lot_number,
            'expires_at' => $item->expires_at,
            'on_hand_quantity' => $item->quantity,
            'reserved_quantity' => 0,
        ]);
    }

    private function increaseStock(ProductVariant $variant, InventoryReceipt $receipt, float $quantity): InventoryStock
    {
        $stock = InventoryStock::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $receipt->warehouse_id)
            ->lockForUpdate()
            ->first();

        if (! $stock instanceof InventoryStock) {
            return InventoryStock::query()->forceCreate([
                'product_variant_id' => $variant->getKey(),
                'warehouse_id' => $receipt->warehouse_id,
                'on_hand_quantity' => $quantity,
                'reserved_quantity' => 0,
                'available_quantity' => $quantity,
            ]);
        }

        $stock->on_hand_quantity = (float) $stock->on_hand_quantity + $quantity;
        $stock->available_quantity = (float) $stock->on_hand_quantity - (float) $stock->reserved_quantity;
        $stock->save();

        return $stock;
    }

    private function nextReceiptNumber(): string
    {
        $maxNumber = InventoryReceipt::query()->whereNotNull('receipt_number')->lockForUpdate()->max('receipt_number');

        return sprintf('REC-%06d', is_string($maxNumber) ? (int) mb_substr($maxNumber, 4) + 1 : 1);
    }
}
