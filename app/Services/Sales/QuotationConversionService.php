<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\QuotationStatus;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationLine;
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
    public function __construct(private DocumentNumberGenerator $numberGenerator) {}

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
     * @param  Collection<int, QuotationLine>  $lines
     * @return list<array{product_variant_id: int, quantity: float, unit_id: int, unit_price: float, tax_amount: float, line_total: float}>
     */
    private function aggregateLines(Collection $lines): array
    {
        $aggregated = [];

        foreach ($lines as $line) {
            $variantId = $line->product_variant_id;
            $aggregated[$variantId] ??= ['product_variant_id' => $variantId, 'quantity' => 0.0, 'tax_amount' => 0.0, 'line_total' => 0.0];
            $aggregated[$variantId]['quantity'] += (float) $line->quantity;
            $aggregated[$variantId]['tax_amount'] = round($aggregated[$variantId]['tax_amount'] + (float) $line->tax_amount, 2);
            $aggregated[$variantId]['line_total'] = round($aggregated[$variantId]['line_total'] + (float) $line->line_total, 2);
        }

        $unitIdsByVariant = ProductVariant::query()->whereIn('id', array_keys($aggregated))->pluck('unit_id', 'id');

        return array_values(array_map(static function (array $row) use ($unitIdsByVariant): array {
            $row['unit_price'] = $row['quantity'] > 0.0
                ? round(($row['line_total'] - $row['tax_amount']) / $row['quantity'], 2)
                : 0.0;

            $unitId = $unitIdsByVariant->get($row['product_variant_id']);
            $row['unit_id'] = is_numeric($unitId) ? (int) $unitId : 0;

            return $row;
        }, $aggregated));
    }
}
