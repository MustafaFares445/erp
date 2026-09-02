<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Purchasing\Exceptions\InvalidPurchaseOrderLine;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotEditable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Draft purchase order writes: header, lines, cost defaulting, and totals.
 *
 * Everything here refuses to touch an order that has left draft (V-06, FR-025).
 * That guard lives in {@see self::assertEditable()} and is called by every
 * mutating method, so there is one place to read rather than one rule repeated
 * at six call sites.
 *
 * Totals are stored, not derived (R-008). The open-commitments report
 * aggregates across every non-terminal order, and a computed accessor would
 * make that unindexable; storing them also makes "the number that was approved
 * is the number that is stored" trivially true.
 *
 * @see /specs/017-purchasing-orders-suppliers/data-model.md §2
 */
final readonly class PurchaseOrderService
{
    public function __construct(
        private PurchaseOrderNumberGenerator $numbers,
        private QuantityNormalizer $quantityNormalizer,
    ) {}

    /**
     * @param  array{supplier_id: int, destination_warehouse_id: int, currency_code: string, ordered_at: string, expected_at?: string|null, notes?: string|null}  $attributes
     */
    public function createDraft(User $actor, array $attributes): PurchaseOrder
    {
        Gate::forUser($actor)->authorize('create', PurchaseOrder::class);

        return DB::transaction(function () use ($actor, $attributes): PurchaseOrder {
            $this->assertSupplierIsUsable((int) $attributes['supplier_id']);
            $this->assertWarehouseIsUsable((int) $attributes['destination_warehouse_id']);

            $order = new PurchaseOrder([
                'supplier_id' => $attributes['supplier_id'],
                'destination_warehouse_id' => $attributes['destination_warehouse_id'],
                'currency_code' => mb_strtoupper($attributes['currency_code']),
                'ordered_at' => $attributes['ordered_at'],
                'expected_at' => $attributes['expected_at'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $order->forceFill([
                'purchase_order_number' => $this->numbers->next(),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * @param  array{supplier_id?: int, destination_warehouse_id?: int, currency_code?: string, ordered_at?: string, expected_at?: string|null, notes?: string|null}  $attributes
     */
    public function updateDraft(User $actor, PurchaseOrder $order, array $attributes): PurchaseOrder
    {
        Gate::forUser($actor)->authorize('update', $order);

        return DB::transaction(function () use ($actor, $order, $attributes): PurchaseOrder {
            $locked = $this->lock($order);
            $this->assertEditable($locked);

            if (isset($attributes['supplier_id'])) {
                $this->assertSupplierIsUsable($attributes['supplier_id']);
            }

            if (isset($attributes['destination_warehouse_id'])) {
                $this->assertWarehouseIsUsable($attributes['destination_warehouse_id']);
            }

            if (isset($attributes['currency_code'])) {
                $attributes['currency_code'] = mb_strtoupper($attributes['currency_code']);
            }

            $locked->fill($attributes);
            $locked->forceFill(['updated_by' => $actor->getKey()])->save();

            return $locked->refresh();
        });
    }

    /**
     * Adds a line, defaulting its cost from the supplier's product reference
     * when the caller does not name one (FR-013).
     *
     * The reference is snapshotted onto the line — both its id and its item
     * number — so the order records the price provenance it was drafted from,
     * even after the reference is later re-costed by a receipt.
     *
     * @param  array{product_variant_id: int, unit_id: int, quantity_ordered: float|string, unit_cost?: float|string|null, expected_at?: string|null}  $attributes
     */
    public function addLine(User $actor, PurchaseOrder $order, array $attributes): PurchaseOrderLine
    {
        Gate::forUser($actor)->authorize('update', $order);

        return DB::transaction(function () use ($actor, $order, $attributes): PurchaseOrderLine {
            $locked = $this->lock($order);
            $this->assertEditable($locked);

            $variantId = (int) $attributes['product_variant_id'];
            $unitId = (int) $attributes['unit_id'];
            $quantityInput = $this->quantityInput($attributes['quantity_ordered']);
            $quantity = (float) $quantityInput;

            $this->assertQuantityIsPositive($quantity);
            $this->assertVariantIsNotAlreadyOnOrder($locked, $variantId, $unitId);

            /** @var ProductVariant $variant */
            $variant = ProductVariant::query()->findOrFail($variantId);
            $this->assertPurchaseUnit($variant, $unitId);
            $snapshot = $this->quantityNormalizer->normalize($variant, $unitId, $quantityInput);

            $reference = $this->referenceFor($locked->supplier_id, $variantId);
            $unitCost = $this->resolveUnitCost(
                $attributes['unit_cost'] ?? null,
                $reference,
                $snapshot->conversionFactorSnapshot,
            );

            $line = new PurchaseOrderLine([
                'purchase_order_id' => $locked->getKey(),
                'product_variant_id' => $variantId,
                'unit_id' => $unitId,
                'supplier_product_reference_id' => $reference?->getKey(),
                'supplier_item_number' => $reference?->supplier_item_number,
                'quantity_ordered' => $snapshot->transactionQuantity,
                'unit_cost' => $unitCost,
                'expected_at' => $attributes['expected_at'] ?? null,
            ]);

            $line->forceFill([
                'transaction_quantity' => $snapshot->transactionQuantity,
                'transaction_unit_id' => $snapshot->transactionUnitId,
                'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
                'base_quantity' => $snapshot->baseQuantity,
                'received_base_quantity' => '0.000000',
                'line_total' => $this->lineTotal((float) $snapshot->transactionQuantity, $unitCost),
            ])->save();

            $this->recomputeTotal($locked, $actor);

            return $line->refresh();
        });
    }

    /**
     * @param  array{quantity_ordered?: float|string, unit_cost?: float|string, expected_at?: string|null}  $attributes
     */
    public function updateLine(User $actor, PurchaseOrderLine $line, array $attributes): PurchaseOrderLine
    {
        $order = $line->purchaseOrder;
        Gate::forUser($actor)->authorize('update', $order);

        return DB::transaction(function () use ($actor, $order, $line, $attributes): PurchaseOrderLine {
            $locked = $this->lock($order);
            $this->assertEditable($locked);

            $quantityInput = $this->quantityInput($attributes['quantity_ordered'] ?? $line->quantity_ordered);
            $quantity = (float) $quantityInput;
            $unitCost = (float) ($attributes['unit_cost'] ?? $line->unit_cost);

            $this->assertQuantityIsPositive($quantity);
            $this->assertUnitCostIsNotNegative($unitCost);

            $variant = $line->productVariant;
            $this->assertPurchaseUnit($variant, $line->unit_id);
            $snapshot = $this->quantityNormalizer->normalize($variant, $line->unit_id, $quantityInput);
            $unitCost = $this->storedCost($unitCost);

            $line->fill([
                'quantity_ordered' => $snapshot->transactionQuantity,
                'unit_cost' => $unitCost,
                'expected_at' => $attributes['expected_at'] ?? $line->expected_at,
            ]);

            $line->forceFill([
                'transaction_quantity' => $snapshot->transactionQuantity,
                'transaction_unit_id' => $snapshot->transactionUnitId,
                'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
                'base_quantity' => $snapshot->baseQuantity,
                'line_total' => $this->lineTotal((float) $snapshot->transactionQuantity, $unitCost),
            ])->save();

            $this->recomputeTotal($locked, $actor);

            return $line->refresh();
        });
    }

    public function removeLine(User $actor, PurchaseOrderLine $line): void
    {
        $order = $line->purchaseOrder;
        Gate::forUser($actor)->authorize('update', $order);

        DB::transaction(function () use ($actor, $order, $line): void {
            $locked = $this->lock($order);
            $this->assertEditable($locked);

            $line->delete();

            $this->recomputeTotal($locked, $actor);
        });
    }

    /**
     * Recomputes the order's stored total from its stored line totals.
     *
     * Summing the stored line totals rather than re-deriving each from quantity
     * and cost is what keeps the document total equal to the sum of the figures
     * printed on it, penny for penny.
     */
    public function recomputeTotal(PurchaseOrder $order, ?User $actor = null): PurchaseOrder
    {
        $total = (float) $order->lines()->sum('line_total');

        $order->forceFill([
            'total_amount' => round($total, 2),
            'updated_by' => $actor?->getKey() ?? $order->updated_by,
        ])->save();

        return $order->refresh();
    }

    /**
     * The single active reference for this supplier and variant, if any.
     *
     * A unique index guarantees there is at most one (V-14), so this never has
     * to choose between rows.
     */
    public function referenceFor(int $supplierId, int $productVariantId): ?SupplierProductReference
    {
        return SupplierProductReference::query()
            ->activeFor($supplierId, $productVariantId)
            ->first();
    }

    /**
     * @throws PurchaseOrderNotEditable
     */
    public function assertEditable(PurchaseOrder $order): void
    {
        if (! $order->status->isEditable()) {
            throw PurchaseOrderNotEditable::status($order);
        }
    }

    private function lock(PurchaseOrder $order): PurchaseOrder
    {
        /** @var PurchaseOrder $locked */
        $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());

        return $locked;
    }

    private function resolveUnitCost(float|string|null $given, ?SupplierProductReference $reference, string $conversionFactor): float
    {
        $cost = $given !== null
            ? (float) $given
            : (float) ($reference instanceof SupplierProductReference ? $reference->purchase_cost : 0) * (float) $conversionFactor;

        $this->assertUnitCostIsNotNegative($cost);

        return $this->storedCost($cost);
    }

    /**
     * Rounds to the precision `unit_cost` actually stores.
     *
     * Without this, a cost of `33.333` is written to the column as `33.33` but
     * multiplied out as `33.333`, so the line total no longer equals the unit
     * cost times the quantity the buyer can see. The document total is the sum
     * of line totals, so the drift would surface on the printed order.
     */
    private function storedCost(float $unitCost): float
    {
        return round($unitCost, 2);
    }

    private function lineTotal(float $quantity, float $unitCost): float
    {
        return round($quantity * $this->storedCost($unitCost), 2);
    }

    private function assertPurchaseUnit(ProductVariant $variant, int $unitId): void
    {
        $allowed = $variant->variantUnits()
            ->where('unit_id', $unitId)
            ->where('is_active', true)
            ->where('is_purchase', true)
            ->exists();

        if (! $allowed) {
            throw InvalidPurchaseOrderLine::invalidPurchaseUnit($variant);
        }
    }

    private function quantityInput(mixed $quantity): string|int
    {
        if (is_int($quantity) || is_string($quantity)) {
            return $quantity;
        }

        if (is_float($quantity) && is_finite($quantity)) {
            return mb_rtrim(mb_rtrim(number_format($quantity, 6, '.', ''), '0'), '.');
        }

        throw InvalidPurchaseOrderLine::quantityNotPositive();
    }

    /**
     * @throws InvalidPurchaseOrderLine
     */
    private function assertQuantityIsPositive(float $quantity): void
    {
        if ($quantity <= 0) {
            throw InvalidPurchaseOrderLine::quantityNotPositive();
        }
    }

    /**
     * @throws InvalidPurchaseOrderLine
     */
    private function assertUnitCostIsNotNegative(float $unitCost): void
    {
        if ($unitCost < 0) {
            throw InvalidPurchaseOrderLine::unitCostNegative();
        }
    }

    /**
     * @throws InvalidPurchaseOrderLine
     */
    private function assertVariantIsNotAlreadyOnOrder(PurchaseOrder $order, int $variantId, int $unitId): void
    {
        $exists = $order->lines()
            ->where('product_variant_id', $variantId)
            ->where('unit_id', $unitId)
            ->exists();

        if (! $exists) {
            return;
        }

        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()->findOrFail($variantId);

        throw InvalidPurchaseOrderLine::duplicateVariant($variant);
    }

    /**
     * @throws InvalidPurchaseOrderLine
     */
    private function assertSupplierIsUsable(int $supplierId): void
    {
        /** @var Supplier $supplier */
        $supplier = Supplier::query()->findOrFail($supplierId);

        if (! $supplier->is_active) {
            throw InvalidPurchaseOrderLine::inactiveSupplier($supplier);
        }
    }

    /**
     * @throws InvalidPurchaseOrderLine
     */
    private function assertWarehouseIsUsable(int $warehouseId): void
    {
        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::query()->findOrFail($warehouseId);

        if (! $warehouse->is_active) {
            throw InvalidPurchaseOrderLine::inactiveWarehouse($warehouse);
        }
    }
}
