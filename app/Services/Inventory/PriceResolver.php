<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\ResolvedPrice;
use App\Enums\PricingTierType;
use App\Enums\ProductStatus;
use App\Enums\ResolvedPriceSource;
use App\Models\CustomerPricingTier;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final readonly class PriceResolver
{
    public function __construct(private PricingTierDiscountCalculator $calculator) {}

    public function resolve(ProductVariant $variant, ?User $customer = null): ResolvedPrice
    {
        return $this->candidates($variant, $customer)[0];
    }

    /** @return list<ResolvedPrice> */
    public function candidates(ProductVariant $variant, ?User $customer = null): array
    {
        $basePrice = (float) ($variant->base_price ?? 0);

        if (! $customer instanceof User || ! $customer->customerProfile()->where('is_active', true)->exists()) {
            return [$this->basePrice($variant, $basePrice)];
        }

        $specificTier = $this->customerSpecificTier($customer);

        if ($specificTier instanceof PricingTier) {
            return [$this->tierPrice($variant, $basePrice, $specificTier, ResolvedPriceSource::CustomerSpecificTier)];
        }

        $productScopedCandidates = $this->productScopedCandidates($variant, $customer, $basePrice);

        if ($productScopedCandidates !== []) {
            usort(
                $productScopedCandidates,
                static fn (ResolvedPrice $left, ResolvedPrice $right): int => [$left->amount, $left->pricingTier?->id] <=> [$right->amount, $right->pricingTier?->id],
            );

            return $productScopedCandidates;
        }

        $generalTier = $this->generalTier($customer);

        if ($generalTier instanceof PricingTier) {
            return [$this->tierPrice($variant, $basePrice, $generalTier, ResolvedPriceSource::GeneralTier)];
        }

        return [$this->basePrice($variant, $basePrice)];
    }

    /** @throws DomainException */
    public function assertAtOrAboveFloor(ProductVariant $variant, float $price): void
    {
        if ($variant->min_price !== null && $price < (float) $variant->min_price) {
            throw new DomainException(__('admin.inventory.pricing.errors.below_floor'));
        }
    }

    private function customerSpecificTier(User $customer): ?PricingTier
    {
        return PricingTier::query()
            ->current()
            ->where('tier_type', PricingTierType::CustomerSpecific)
            ->where('customer_user_id', $customer->getKey())
            ->orderBy('id')
            ->first();
    }

    private function generalTier(User $customer): ?PricingTier
    {
        return CustomerPricingTier::query()
            ->where('customer_user_id', $customer->getKey())
            ->where('is_active', true)
            ->withWhereHas('pricingTier', function (Builder|Relation $query): void {
                $query
                    ->where('is_active', true)
                    ->where('tier_type', PricingTierType::General)
                    ->where(fn (Builder $dates): Builder => $dates->whereNull('valid_from')->orWhereDate('valid_from', '<=', today()))
                    ->where(fn (Builder $dates): Builder => $dates->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()));
            })
            ->orderBy('id')
            ->first()
            ?->pricingTier;
    }

    /** @return list<ResolvedPrice> */
    private function productScopedCandidates(ProductVariant $variant, User $customer, float $basePrice): array
    {
        if ($basePrice <= 0 || ! $variant->is_active || $variant->status !== ProductStatus::Active) {
            return [];
        }

        $tiers = PricingTier::query()
            ->current()
            ->where('tier_type', PricingTierType::ProductScoped)
            ->whereHas('products', fn (Builder $query): Builder => $query
                ->whereKey($variant->product_id)
                ->where('is_active', true)
                ->where('status', ProductStatus::Active->value))
            ->whereHas('assignments', fn (Builder $query): Builder => $query
                ->where('customer_user_id', $customer->getKey())
                ->where('is_active', true))
            ->orderBy('id')
            ->get();
        $candidates = [];

        foreach ($tiers as $tier) {
            try {
                $candidates[] = $this->tierPrice($variant, $basePrice, $tier, ResolvedPriceSource::ProductScopedTier);
            } catch (DomainException) {
                continue;
            }
        }

        return $candidates;
    }

    private function tierPrice(ProductVariant $variant, float $basePrice, PricingTier $tier, ResolvedPriceSource $source): ResolvedPrice
    {
        $discount = $this->calculator->calculate($basePrice, $tier->discount_type, (float) $tier->discount_value);
        $minimumPrice = $variant->min_price === null ? null : (float) $variant->min_price;

        return new ResolvedPrice(
            amount: $discount['amount'],
            pricingTier: $tier,
            source: $source,
            discountType: $tier->discount_type,
            discountValue: (float) $tier->discount_value,
            baseAmount: $basePrice,
            discountAmount: $discount['discount_amount'],
            minimumPrice: $minimumPrice,
            isBelowFloor: $minimumPrice !== null && $discount['amount'] < $minimumPrice,
        );
    }

    private function basePrice(ProductVariant $variant, float $basePrice): ResolvedPrice
    {
        $minimumPrice = $variant->min_price === null ? null : (float) $variant->min_price;

        return new ResolvedPrice(
            amount: $basePrice,
            pricingTier: null,
            source: ResolvedPriceSource::Base,
            baseAmount: $basePrice,
            discountAmount: 0,
            minimumPrice: $minimumPrice,
            isBelowFloor: $minimumPrice !== null && $basePrice < $minimumPrice,
        );
    }
}
