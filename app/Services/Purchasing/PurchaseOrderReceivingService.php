<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Data\Inventory\NormalizedQuantity;
use App\Enums\OperationType;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotReceivable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Opens a draft inventory receipt against a purchase order.
 *
 * This is the whole of purchasing's involvement in receiving. It creates an
 * {@see InventoryOperation} of type `receipt`, pre-fills its lines from the
 * order's outstanding quantities, and points it back at the order through the
 * existing `source_document` morph. Stock moves later, when that operation is
 * completed through `InventoryOperationService` — nothing here writes
 * `inventory_stocks` or `inventory_movements`, and an architecture test enforces
 * that rather than trusting review (R-001, SC-002).
 *
 * The morph was designed for exactly this. `InventoryOperation::sourceDocument()`
 * already documents "a purchase order for a receipt", and `inventory_operations`
 * already carries `supplier_id` and `supplier_reference` columns that no live
 * flow populated, because only a purchasing flow would.
 *
 * @see /specs/017-purchasing-orders-suppliers/research.md R-001
 */
final readonly class PurchaseOrderReceivingService
{
    private const QUANTITY_SCALE = 6;

    public function __construct(private QuantityNormalizer $quantityNormalizer) {}

    public function initiate(User $actor, PurchaseOrder $order): InventoryOperation
    {
        Gate::forUser($actor)->authorize('receive', $order);

        return DB::transaction(function () use ($actor, $order): InventoryOperation {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if (! $locked->status->isReceivable()) {
                throw PurchaseOrderNotReceivable::status($locked);
            }

            $warehouse = $this->assertWarehouseIsUsable($locked);

            $operation = new InventoryOperation([
                'operation_type' => OperationType::Receipt,
                'destination_warehouse_id' => $warehouse->getKey(),
                'supplier_id' => $locked->supplier_id,
                'source_document_type' => PurchaseOrder::class,
                'source_document_id' => $locked->getKey(),
                'supplier_reference' => $locked->purchase_order_number,
            ]);

            $operation->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->prefillOutstandingLines($locked, $operation);

            return $operation->refresh();
        });
    }

    /**
     * Copies every line with quantity still outstanding onto the receipt.
     *
     * Fully received lines are skipped rather than added at zero: a receipt line
     * of zero would have to be either ignored or rejected downstream, and
     * omitting it says the same thing without the ambiguity. Lot, serial, and
     * expiry details are left for the warehouse to fill in on the operation
     * itself — purchasing has no way to know them at order time.
     */
    private function prefillOutstandingLines(PurchaseOrder $order, InventoryOperation $operation): void
    {
        $lines = $order->lines()->with('productVariant')->orderBy('id')->lockForUpdate()->get();

        foreach ($lines as $line) {
            $snapshot = $this->snapshotFor($line);
            $outstandingBaseQuantity = bcsub(
                $snapshot->baseQuantity,
                $this->receivedBaseQuantity($line, $snapshot),
                self::QUANTITY_SCALE,
            );

            if (bccomp($outstandingBaseQuantity, '0', self::QUANTITY_SCALE) <= 0) {
                continue;
            }

            $outstanding = bcdiv($outstandingBaseQuantity, $snapshot->conversionFactorSnapshot, self::QUANTITY_SCALE);

            $operation->lines()->create([
                'product_variant_id' => $line->product_variant_id,
                'unit_id' => $line->unit_id,
                'quantity' => $outstanding,
                'unit_cost' => $line->unit_cost,
                'purchase_order_line_id' => $line->id,
            ]);
        }
    }

    private function snapshotFor(PurchaseOrderLine $line): NormalizedQuantity
    {
        $variant = $line->productVariant;

        if (
            $line->transaction_quantity !== null
            && $line->transaction_unit_id !== null
            && $line->conversion_factor_snapshot !== null
            && $line->base_quantity !== null
        ) {
            return new NormalizedQuantity(
                transactionQuantity: $line->transaction_quantity,
                transactionUnitId: $line->transaction_unit_id,
                conversionFactorSnapshot: $line->conversion_factor_snapshot,
                baseUnitId: $this->baseUnitId($variant),
                baseQuantity: $line->base_quantity,
            );
        }

        $snapshot = $this->quantityNormalizer->normalize($variant, $line->unit_id, (string) $line->quantity_ordered);
        $receivedBaseQuantity = $line->quantity_received === '0.000000'
            ? '0.000000'
            : $this->quantityNormalizer->normalize($variant, $line->unit_id, (string) $line->quantity_received)->baseQuantity;

        $line->forceFill([
            'transaction_quantity' => $snapshot->transactionQuantity,
            'transaction_unit_id' => $snapshot->transactionUnitId,
            'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
            'base_quantity' => $snapshot->baseQuantity,
            'received_base_quantity' => $receivedBaseQuantity,
        ])->save();

        return $snapshot;
    }

    /** @return numeric-string */
    private function receivedBaseQuantity(PurchaseOrderLine $line, NormalizedQuantity $snapshot): string
    {
        if ($line->received_base_quantity !== null) {
            return $line->received_base_quantity;
        }

        $variant = $line->productVariant;

        return $line->quantity_received === '0.000000'
            ? '0.000000'
            : $this->quantityNormalizer->normalize(
                $variant,
                $snapshot->transactionUnitId,
                (string) $line->quantity_received,
            )->baseQuantity;
    }

    private function baseUnitId(ProductVariant $variant): int
    {
        if (! is_int($variant->unit_id)) {
            throw new \LogicException('Purchase order variants require an integer base unit identifier.');
        }

        return $variant->unit_id;
    }

    /**
     * @throws PurchaseOrderNotReceivable
     */
    private function assertWarehouseIsUsable(PurchaseOrder $order): Warehouse
    {
        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::query()->findOrFail($order->destination_warehouse_id);

        // Re-checked here and not only at drafting (FR-044): an order sent weeks
        // ago may name a warehouse that has since been deactivated, and stock
        // must not be received into one.
        if (! $warehouse->is_active) {
            throw PurchaseOrderNotReceivable::inactiveWarehouse($warehouse);
        }

        return $warehouse;
    }
}
