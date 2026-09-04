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
 * **Compatible aggregation only.** Quotation rows may be aggregated only
 * when variant, transaction UOM, conversion snapshot, commercial price and
 * immutable price provenance are identical. A later tier/floor change can
 * therefore never collapse two historically distinct pricing decisions into
 * one order line. The order's document totals are copied from the quotation
 * verbatim rather than recomputed (invariant I-7).
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

            return $order->refresh()->load('lines');
        });
    }

    /**
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
     *     line_total: float,
     *     resolved_price_source: \App\Enums\ResolvedPriceSource|null,
     *     resolved_price_tier_id: int|null,
     *     price_floor_override_id: int|null,
     *     list_price_minor: int|null,
     *     floor_price_minor: int|null
     * }>
     */
    private function aggregateLines(Collection $lines): array
    {
        $aggregated = [];

        foreach ($lines as $line) {
            $snapshot = $this->snapshotFor($line);
            $key = implode(':', array_map(
                static fn (mixed $value): string => $value === null ? 'null' : (string) $value,
                [
                    $line->product_variant_id,
                    $snapshot->transactionUnitId,
                    $snapshot->conversionFactorSnapshot,
                    $line->unit_price,
                    $line->resolved_price_source?->value,
                    $line->resolved_price_tier_id,
                    $line->price_floor_override_id,
                    $line->list_price_minor,
                    $line->floor_price_minor,
                ],
            ));

            $aggregated[$key] ??= [
                'product_variant_id' => (int) $line->product_variant_id,
                'quantity' => '0.000000',
                'unit_id' => $snapshot->transactionUnitId,
                'transaction_quantity' => '0.000000',
                'transaction_unit_id' => $snapshot->transactionUnitId,
                'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
                'base_quantity' => '0.000000',
                'unit_price' => (float) $line->unit_price,
                'tax_amount' => 0.0,
                'line_total' => 0.0,
                ...$line->priceProvenanceAttributes(),
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
