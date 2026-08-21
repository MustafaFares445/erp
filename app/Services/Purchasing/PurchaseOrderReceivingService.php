<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\OperationType;
use App\Models\InventoryOperation;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
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
        $lines = $order->lines()->with('productVariant')->orderBy('id')->get();

        foreach ($lines as $line) {
            $outstanding = $line->outstandingQuantity();

            if ($outstanding <= 0.0) {
                continue;
            }

            $operation->lines()->create([
                'product_variant_id' => $line->product_variant_id,
                'unit_id' => $line->unit_id,
                'quantity' => $outstanding,
                'unit_cost' => $line->unit_cost,
            ]);
        }
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
