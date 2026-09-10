<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\PriceListItem;
use App\Models\PriceListItemPricingTier;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;

class ProductPricingService
{
    private const ZERO_MONEY = '0.00';

    public function __construct(
        private readonly LinePricingService $linePricing,
        private readonly PricingTierService $pricingTierService,
    ) {}

    public function resolveUnitPrice(Customer $customer, ProductVariant $variant, string $quantity): string
    {
        $priceListId = $customer->price_list_id;

        if ($priceListId !== null) {
            $priceListItem = PriceListItem::query()
                ->where('price_list_id', $priceListId)
                ->where('product_variant_id', $variant->id)
                ->first();

            if ($priceListItem) {
                return $this->pricingTierService->resolveUnitPrice(
                    $priceListItem,
                    $quantity,
                    (string) $variant->price
                );
            }
        }

        return BigDecimal::of((string) $variant->price)->toScale(2, RoundingMode::HALF_UP)->__toString();
    }

    public function buildVariantCatalog(Customer $customer, Collection $variants, string $quantity = '1.000'): array
    {
        $catalog = [];

        foreach ($variants as $variant) {
            $unitPrice = $this->resolveUnitPrice($customer, $variant, $quantity);
            $discountPercent = $this->resolveDiscountPercent($customer, $variant, $quantity);
            $taxRate = $this->resolveTaxRate($customer, $variant);

            $pricing = $this->linePricing->calculate(
                quantity: $quantity,
                unitPrice: $unitPrice,
                discountPercent: $discountPercent,
                taxRate: $taxRate,
            );

            $catalog[] = [
                'product_variant_id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'available_quantity' => (string) ($variant->available_quantity ?? '0.000'),
                'unit_price' => $pricing['unit_price'],
                'line_subtotal' => $pricing['line_subtotal'],
                'discount_percent' => $pricing['discount_percent'],
                'discount_amount' => $pricing['discount_amount'],
                'tax_rate' => $pricing['tax_rate'],
                'tax_amount' => $pricing['tax_amount'],
                'line_total' => $pricing['line_total'],
            ];
        }

        return $catalog;
    }

    private function resolveDiscountPercent(Customer $customer, ProductVariant $variant, string $quantity): string
    {
        $priceListId = $customer->price_list_id;

        if ($priceListId === null) {
            return self::ZERO_MONEY;
        }

        $priceListItem = PriceListItem::query()
            ->where('price_list_id', $priceListId)
            ->where('product_variant_id', $variant->id)
            ->first();

        if (! $priceListItem) {
            return self::ZERO_MONEY;
        }

        $tier = $this->resolvePricingTier($priceListItem, $quantity);

        if ($tier?->discount_percentage !== null) {
            return BigDecimal::of((string) $tier->discount_percentage)
                ->toScale(2, RoundingMode::HALF_UP)
                ->__toString();
        }

        if ($priceListItem->discount_type === 'percentage' && $priceListItem->discount_value !== null) {
            return BigDecimal::of((string) $priceListItem->discount_value)
                ->toScale(2, RoundingMode::HALF_UP)
                ->__toString();
        }

        return self::ZERO_MONEY;
    }

    private function resolveTaxRate(Customer $customer, ProductVariant $variant): string
    {
        if ($customer->tax_exempt === true) {
            return self::ZERO_MONEY;
        }

        $taxCode = $variant->taxCode;

        if (! $taxCode || $taxCode->is_active !== true) {
            return self::ZERO_MONEY;
        }

        $rate = $taxCode->taxRates()
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->first();

        if (! $rate) {
            return self::ZERO_MONEY;
        }

        return BigDecimal::of((string) $rate->rate)
            ->toScale(2, RoundingMode::HALF_UP)
            ->__toString();
    }

    private function resolvePricingTier(PriceListItem $priceListItem, string $quantity): ?PriceListItemPricingTier
    {
        return $priceListItem->pricingTiers()
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($query) use ($quantity) {
                $query->whereNull('max_quantity')
                    ->orWhere('max_quantity', '>=', $quantity);
            })
            ->orderByDesc('min_quantity')
            ->first();
    }
}
