<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\ReceiptMovementContext;
use App\Enums\MovementType;
use App\Enums\ReceiptStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class InventoryReceivingService
{
    public function __construct(
        private ProductPricingService $productPricingService,
        private InventoryAlertService $inventoryAlertService,
        private InventoryBalanceService $inventoryBalanceService,
        private ProductTypeGuard $productTypeGuard,
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

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => ReceiptStatus::Draft->value],
                    'attributes' => ['status' => ReceiptStatus::Confirmed->value, 'receipt_number' => $receiptNumber],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('inventory.receipt.confirmed');
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

        // The product type's own quantity rule, on top of the unit's: a machine is never
        // fractional whatever its unit permits. Delegated so this legacy path and the unified
        // operation path cannot drift apart on what a type allows.
        $this->productTypeGuard->assertQuantity($variant, (float) $item->quantity, $item->unit);

        $productType = $variant->productType();

        $serializedUnits = $productType?->tracksSerials() === true
            ? $this->assignSerializedUnits($item, $receipt, $variant)
            : new Collection;

        $lot = $productType?->tracksExpiry() === true ? $this->recordLot($item, $receipt, $variant) : null;
        $stock = $this->inventoryBalanceService->receive(
            $variant,
            $receipt->warehouse_id,
            (float) $item->quantity,
        );
        $this->inventoryAlertService->syncStock($stock);

        if ($lot instanceof InventoryLot) {
            $this->inventoryAlertService->syncExpiry($lot);
        }

        $this->recordMovements(
            new ReceiptMovementContext($item, $receipt, $lot, $actor),
            $serializedUnits,
        );

        if ($item->purchase_cost !== null) {
            $this->productPricingService->updateCostFromInventory($variant, (float) $item->purchase_cost, $actor);
        }

        activity()
            ->performedOn($item)
            ->causedBy($actor)
            ->withChanges([
                'old' => ['on_hand_quantity' => (float) $stock->on_hand_quantity - (float) $item->quantity],
                'attributes' => ['on_hand_quantity' => (float) $stock->on_hand_quantity],
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('inventory.receipt.item_received');
    }

    /**
     * @return Collection<int, SerializedInventoryUnit>
     *
     * @throws DomainException
     */
    private function assignSerializedUnits(
        InventoryReceiptItem $item,
        InventoryReceipt $receipt,
        ProductVariant $variant,
    ): Collection {
        // Reaching here means the variant is a machine, and ProductTypeGuard::assertQuantity()
        // has already rejected any fractional machine quantity — so the old fractional check
        // that stood here was unreachable duplication of a rule the type now owns.
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
                'status' => SerializedInventoryUnitStatus::Available,
            ])->save();
        }

        return $serializedUnits;
    }

    /** @param Collection<int, SerializedInventoryUnit> $serializedUnits */
    private function recordMovements(
        ReceiptMovementContext $context,
        Collection $serializedUnits,
    ): void {
        if ($serializedUnits->isEmpty()) {
            $this->recordMovement($context, null, (float) $context->item->quantity);

            return;
        }

        foreach ($serializedUnits as $serializedUnit) {
            $this->recordMovement($context, $serializedUnit->getKey(), 1.0);
        }
    }

    private function recordMovement(
        ReceiptMovementContext $context,
        mixed $serializedUnitId,
        float $quantity,
    ): void {
        InventoryMovement::query()->forceCreate([
            'product_variant_id' => $context->item->product_variant_id,
            'warehouse_id' => $context->receipt->warehouse_id,
            'movement_type' => MovementType::Receipt,
            'quantity' => $quantity,
            'source_type' => 'receipt',
            'source_id' => $context->receipt->getKey(),
            'inventory_receipt_item_id' => $context->item->getKey(),
            'serialized_inventory_unit_id' => $serializedUnitId,
            'inventory_lot_id' => $context->lot?->getKey(),
            'status' => 'confirmed',
            'created_by' => $context->actor->getKey(),
            'notes' => $context->receipt->supplier_reference,
        ]);
    }

    /** @throws DomainException */
    private function recordLot(InventoryReceiptItem $item, InventoryReceipt $receipt, ProductVariant $variant): InventoryLot
    {
        if ($item->expires_at === null) {
            throw new DomainException(__('admin.inventory.receipt.errors.expiry_required'));
        }

        // Kept as its own check above so this path's established error message survives, then
        // delegated for the remaining rule — an expiry date must not already be in the past.
        $this->productTypeGuard->assertInboundExpiry($variant, $item->expires_at);

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

    private function nextReceiptNumber(): string
    {
        $maxNumber = InventoryReceipt::query()->whereNotNull('receipt_number')->lockForUpdate()->max('receipt_number');

        return sprintf('REC-%06d', is_string($maxNumber) ? (int) mb_substr($maxNumber, 4) + 1 : 1);
    }
}
