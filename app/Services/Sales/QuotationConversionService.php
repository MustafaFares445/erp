<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Inventory\NormalizedQuantity;
use App\Enums\QuotationStatus;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Sales\Exceptions\InvalidQuotationTransition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Converts an accepted quotation into a priced order (FR-024, FR-029,
 * data-model.md §6) — the highest-regression-risk step in the feature,
 * because the order it creates enters the fulfillment machinery `orders`
 * and `order_lines` already serve six other services.
 *
 * **The one aggregation.** `order_lines` carries a built unique index on
 * `(order_id, product_variant_id)` that a quotation has no equivalent of, so
 * a quotation with the same variant on two lines is aggregated into one
 * order line here: quantities, tax and line totals sum, and `unit_price` is
 * derived from the aggregate. That derivation is the only place in the
 * feature a rounding difference can appear, which is exactly why the
 * order's document totals are copied from the quotation **verbatim** below
 * rather than recomputed from the aggregated lines — a sub-cent difference
 * can land in a line's unit price but can never change what the customer
 * owes (invariant I-7).
 */
final readonly class QuotationConversionService
{
    public function __construct(
        private DocumentNumberGenerator $numberGenerator,
        private QuantityNormalizer $quantityNormalizer,
    ) {}

    public function convert(Quotation $quotation): Order
    {
        return DB::transaction(function () use ($quotation): Order {
            if ($quotation->status === QuotationStatus::ConvertedToDelivery || $quotation->converted_order_id !== null) {
                throw InvalidQuotationTransition::alreadyConverted(
                    (string) $quotation->quotation_number,
                    (string) $quotation->convertedOrder?->order_number,
                );
            }

            if ($quotation->status !== QuotationStatus::Accepted) {
                throw InvalidQuotationTransition::notAcceptedStatus(
                    (string) $quotation->quotation_number,
                    $quotation->status->label(),
                );
            }

            $order = new Order([
                'order_number' => $this->numberGenerator->next(Order::query(), 'order_number', 'SO-'),
                'customer_id' => $quotation->customer_id,
                'quotation_id' => $quotation->getKey(),
                'payment_term_id' => $quotation->payment_term_id,
                'subtotal' => $quotation->subtotal,
                'tax_total' => $quotation->tax_total,
                'grand_total' => $quotation->grand_total,
                'status' => 'ready',
            ]);
            $order->save();

            foreach ($this->aggregateLines($quotation->lines) as $line) {
                $order->lines()->create($line);
            }

            $quotation->update([
                'status' => QuotationStatus::ConvertedToDelivery,
                'converted_order_id' => $order->getKey(),
            ]);

            return $order->refresh();
        });
    }

    /**
     * Aggregate only lines that share the same variant and frozen transaction-UOM
     * meaning. Two lines for the same SKU in Box and Piece must remain distinct
     * commercial order lines even though both normalize to the same base stock UOM.
     *
     * @param  Collection<int, QuotationLine>  $lines
     * @return list<array{
     *     product_variant_id: int,
     *     quantity: numeric-string,
     *     unit_id: int,
     *     transaction_quantity: numeric-string,
     *     transaction_unit_id: int,
     *     conversion_factor_snapshot: numeric-string,
     *     base_quantity: numeric-string,
     *     unit_price: float,
     *     tax_amount: float,
     *     line_total: float
     * }>
     */
    private function aggregateLines(Collection $lines): array
    {
        $aggregated = [];

        foreach ($lines as $line) {
            $snapshot = $this->snapshotFor($line);
            $key = implode(':', [
                $line->product_variant_id,
                $snapshot->transactionUnitId,
                $snapshot->conversionFactorSnapshot,
            ]);

            $aggregated[$key] ??= [
                'product_variant_id' => $line->product_variant_id,
                'quantity' => '0.000000',
                'unit_id' => $snapshot->transactionUnitId,
                'transaction_quantity' => '0.000000',
                'transaction_unit_id' => $snapshot->transactionUnitId,
                'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
                'base_quantity' => '0.000000',
                'tax_amount' => 0.0,
                'line_total' => 0.0,
            ];

            $aggregated[$key]['quantity'] = bcadd(
                $aggregated[$key]['quantity'],
                $snapshot->transactionQuantity,
                6,
            );
            $aggregated[$key]['transaction_quantity'] = $aggregated[$key]['quantity'];
            $aggregated[$key]['base_quantity'] = bcadd(
                $aggregated[$key]['base_quantity'],
                $snapshot->baseQuantity,
                6,
            );
            $aggregated[$key]['tax_amount'] = round(
                $aggregated[$key]['tax_amount'] + (float) $line->tax_amount,
                2,
            );
            $aggregated[$key]['line_total'] = round(
                $aggregated[$key]['line_total'] + (float) $line->line_total,
                2,
            );
        }

        foreach ($aggregated as &$row) {
            $quantity = (float) $row['transaction_quantity'];
            $row['unit_price'] = $quantity > 0.0
                ? round(($row['line_total'] - $row['tax_amount']) / $quantity, 2)
                : 0.0;
        }
        unset($row);

        return array_values($aggregated);
    }

    private function snapshotFor(QuotationLine $line): NormalizedQuantity
    {
        $variant = $line->productVariant;

        if (! $variant instanceof ProductVariant) {
            throw new \LogicException('A quotation line requires a product variant.');
        }

        if (
            $line->transaction_quantity !== null
            && $line->transaction_unit_id !== null
            && $line->conversion_factor_snapshot !== null
            && $line->base_quantity !== null
        ) {
            if (! is_int($variant->unit_id)) {
                throw new \LogicException('A quotation variant requires an integer base unit identifier.');
            }

            return new NormalizedQuantity(
                transactionQuantity: $line->transaction_quantity,
                transactionUnitId: $line->transaction_unit_id,
                conversionFactorSnapshot: $line->conversion_factor_snapshot,
                baseUnitId: $variant->unit_id,
                baseQuantity: $line->base_quantity,
            );
        }

        $unitId = $line->unit_id ?? $variant->unit_id;

        if (! is_int($unitId)) {
            throw new \LogicException('A legacy quotation line has no usable transaction UOM.');
        }

        $snapshot = $this->quantityNormalizer->normalize($variant, $unitId, (string) $line->quantity);

        // Bounded compatibility for rows created before Phase 11. Snapshot once so
        // every subsequent read/conversion sees the same historical quantity basis.
        $line->forceFill([
            'unit_id' => $snapshot->transactionUnitId,
            'transaction_quantity' => $snapshot->transactionQuantity,
            'transaction_unit_id' => $snapshot->transactionUnitId,
            'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
            'base_quantity' => $snapshot->baseQuantity,
        ])->save();

        return $snapshot;
    }

}
