<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\PriceListItem;
use App\Models\ProductVariant;

class PriceResolver
{
    public const SOURCE_PRICING_TIER = 'pricing_tier';

    public const SOURCE_CUSTOMER = 'customer';

    public const SOURCE_BASE = 'base';

    public function __construct(
        private readonly PricingTierService $pricingTierService,
    ) {}

    /**
     * @return array{unit_price:string, source:string}
     */
    public function resolve(Customer $customer, ProductVariant $variant, string $quantity): array
    {
        $priceListId = $customer->price_list_id;

        if ($priceListId !== null) {
            $priceListItem = PriceListItem::query()
                ->where('price_list_id', $priceListId)
                ->where('product_variant_id', $variant->id)
                ->first();

            if ($priceListItem) {
                return [
                    'unit_price' => $this->pricingTierService->resolveUnitPrice(
                        $priceListItem,
                        $quantity,
                        (string) $variant->price
                    ),
                    'source' => self::SOURCE_PRICING_TIER,
                ];
            }
        }

        if ($customer->price_list_id !== null) {
            $override = PriceListItem::query()
                ->where('price_list_id', $customer->price_list_id)
                ->where('product_variant_id', $variant->id)
                ->value('unit_price');

            if ($override !== null) {
                return [
                    'unit_price' => (string) $override,
                    'source' => self::SOURCE_CUSTOMER,
                ];
            }
        }

        return [
            'unit_price' => (string) $variant->price,
            'source' => self::SOURCE_BASE,
        ];
    }
}
