<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Inventory\PriceResolver;
use DomainException;

/**
 * Prices only order lines whose commercial document did not originate from a
 * quotation. Quotation-derived lines already carry a frozen customer decision
 * and must never be re-resolved here.
 */
final readonly class DirectOrderLinePricingService
{
    public function __construct(
        private PriceResolver $resolver,
        private PriceProvenanceService $provenance,
        private LineTotalCalculator $calculator,
    ) {}

    public function prepare(OrderLine $line): void
    {
        $order = Order::query()
            ->with('customer.user')
            ->find($line->order_id);

        if (! $order instanceof Order || $order->quotation_id !== null) {
            return;
        }

        $variant = ProductVariant::query()->find($line->product_variant_id);
        if (! $variant instanceof ProductVariant) {
            throw new DomainException('A direct sales order line requires a product variant.');
        }

        $customer = $order->customer;
        $customerUser = $customer instanceof CustomerProfile && $customer->user instanceof User
            ? $customer->user
            : null;
        $factor = max(0.000001, (float) ($line->conversion_factor_snapshot ?? 1));
        $quantity = (float) ($line->transaction_quantity ?? $line->quantity);

        if ($line->unit_price === null) {
            $resolved = $this->resolver->resolve($variant, $customerUser);
            $unitPrice = round($resolved->amount * $factor, 2);
            $priceEvidence = $this->provenance->fromResolved($resolved);
        } else {
            $unitPrice = (float) $line->unit_price;
            $priceEvidence = $this->provenance->forManualPrice(
                variant: $variant,
                customer: $customerUser,
                transactionUnitPrice: $unitPrice,
                conversionFactor: $factor,
                priceFloorOverrideId: $line->price_floor_override_id,
            );
        }

        $taxAmount = $line->tax_amount !== null
            ? round((float) $line->tax_amount, 2)
            : $this->calculator->defaultTax(
                $quantity,
                $unitPrice,
                (float) SalesSetting::current()->default_tax_percent,
            );

        $line->forceFill([
            'unit_price' => $unitPrice,
            'tax_amount' => $taxAmount,
            'line_total' => $this->calculator->lineTotal($quantity, $unitPrice, $taxAmount),
            ...$priceEvidence,
        ]);
    }

    public function refreshOrderTotals(OrderLine $line): void
    {
        $order = Order::query()->find($line->order_id);

        if (! $order instanceof Order || $order->quotation_id !== null) {
            return;
        }

        $lines = $order->lines()->get(['quantity', 'unit_price', 'tax_amount', 'line_total']);
        $subtotal = round((float) $lines->sum(
            static fn (OrderLine $orderLine): float => (float) $orderLine->line_total - (float) $orderLine->tax_amount,
        ), 2);
        $tax = round((float) $lines->sum('tax_amount'), 2);

        $order->forceFill([
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'grand_total' => round($subtotal + $tax, 2),
        ])->save();
    }
}
