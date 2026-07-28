<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\ResolvedPrice;
use App\Models\CustomerPricingTier;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final class PriceResolver
{
    public function resolve(ProductVariant $variant, ?User $customer = null): ResolvedPrice
    {
        $tier = $this->applicableTier($customer);
        $basePrice = (float) ($variant->base_price ?? 0);

        if (! $tier instanceof PricingTier) {
            return new ResolvedPrice($basePrice, null);
        }

        return new ResolvedPrice(
            amount: round($basePrice * (1 - ((float) $tier->discount_percent / 100)), 2),
            pricingTier: $tier,
        );
    }

    /** @throws DomainException */
    public function assertAtOrAboveFloor(ProductVariant $variant, float $price): void
    {
        if ($variant->min_price !== null && $price < (float) $variant->min_price) {
            throw new DomainException(__('admin.inventory.pricing.errors.below_floor'));
        }
    }

    private function applicableTier(?User $customer): ?PricingTier
    {
        if (! $customer instanceof User) {
            return null;
        }

        $customerTier = PricingTier::query()
            ->where('customer_user_id', $customer->getKey())
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($customerTier instanceof PricingTier) {
            return $customerTier;
        }

        return CustomerPricingTier::query()
            ->where('customer_user_id', $customer->getKey())
            ->where('is_active', true)
            ->withWhereHas('pricingTier', function (Builder|Relation $query): void {
                $query->whereNull('customer_user_id')->where('is_active', true);
            })
            ->orderBy('id')
            ->first()
            ?->pricingTier;
    }
}
