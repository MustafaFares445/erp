<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierProductReference;
use Illuminate\Database\Eloquent\Collection;

/**
 * Keeps supplier reference costs current from what was actually paid (FR-048).
 *
 * Last-paid price, not a moving average: averaging needs landed cost — freight,
 * duty — which this feature places out of scope, and a misleading average is
 * worse than a plain figure that says what it is (R-009).
 *
 * The previous value is preserved by the activity log rather than by a
 * `supplier_cost_history` table. No requirement reads a cost time series, and
 * ADR 0005's log already captures old and new attribute values, so the table
 * would be schema without a reader.
 *
 * @see /specs/017-purchasing-orders-suppliers/research.md R-009
 */
final readonly class SupplierCostWritebackService
{
    /**
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @param  array<string, array{quantity: float, unit_cost: float|null}>  $incoming
     */
    public function apply(PurchaseOrder $order, Collection $lines, array $incoming): void
    {
        foreach ($lines as $line) {
            $entry = $incoming[$line->product_variant_id.':'.$line->unit_id] ?? null;
            if ($entry === null) {
                continue;
            }

            if ($entry['unit_cost'] === null) {
                continue;
            }

            if ($entry['quantity'] <= 0.0) {
                continue;
            }

            $this->record($order, $line, round($entry['unit_cost'], 2));
        }
    }

    /**
     * Updates the supplier's active reference for this variant, or creates one
     * when none exists (FR-049).
     *
     * Creating rather than skipping matters: a variant first bought on an ad-hoc
     * order would otherwise never gain a reference, and every future order for it
     * would keep defaulting to zero.
     */
    private function record(PurchaseOrder $order, PurchaseOrderLine $line, float $unitCost): void
    {
        /** @var SupplierProductReference|null $reference */
        $reference = SupplierProductReference::query()
            ->activeFor($order->supplier_id, $line->product_variant_id)
            ->first();

        if ($reference instanceof SupplierProductReference) {
            $previousCost = $reference->purchase_cost;

            $reference->forceFill([
                'purchase_cost' => $unitCost,
                // The currency follows the order without conversion: this feature
                // converts nothing, so a reference re-costed from a USD order is
                // a USD reference (FR-050).
                'currency_code' => $order->currency_code,
            ])->save();

            $this->audit($reference, $previousCost, $unitCost, $order, 'purchasing.supplier_reference.recosted');

            return;
        }

        $created = SupplierProductReference::query()->create([
            'supplier_id' => $order->supplier_id,
            'product_variant_id' => $line->product_variant_id,
            'supplier_item_number' => $this->itemNumberFor($line),
            'purchase_cost' => $unitCost,
            'currency_code' => $order->currency_code,
            'is_active' => true,
        ]);

        $this->audit($created, null, $unitCost, $order, 'purchasing.supplier_reference.created');
    }

    /**
     * A reference needs an item number. The line's snapshot is used when it has
     * one; otherwise the variant's own SKU is the closest thing to a supplier
     * item number this feature can offer.
     */
    private function itemNumberFor(PurchaseOrderLine $line): string
    {
        $snapshot = $line->supplier_item_number;

        if (is_string($snapshot) && $snapshot !== '') {
            return $snapshot;
        }

        return $line->productVariant->sku;
    }

    private function audit(
        SupplierProductReference $reference,
        ?string $previousCost,
        float $newCost,
        PurchaseOrder $order,
        string $event,
    ): void {
        activity()
            ->performedOn($reference)
            ->withChanges([
                'old' => ['purchase_cost' => $previousCost],
                'attributes' => ['purchase_cost' => number_format($newCost, 2, '.', ''), 'currency_code' => $order->currency_code],
            ])
            ->withProperties([
                'source_channel' => 'dashboard',
                'purchase_order_number' => $order->purchase_order_number,
            ])
            ->log($event);
    }
}
