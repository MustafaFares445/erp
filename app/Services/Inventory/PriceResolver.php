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
            $price = $this->tryTierPrice($variant, $basePrice, $specificTier, ResolvedPriceSource::CustomerSpecificTier);

            if ($price instanceof ResolvedPrice) {
                return [$price];
            }
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
            $price = $this->tryTierPrice($variant, $basePrice, $generalTier, ResolvedPriceSource::GeneralTier);

            if ($price instanceof ResolvedPrice) {
                return [$price];
            }
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

    /**
     * The tier that would apply to a general, not-yet-chosen product for
     * this customer — a customer-specific tier if one exists, otherwise
     * their assigned general tier. Product-scoped tiers are deliberately
     * excluded, since those only apply once a specific product is known.
     * Used to show which tier a quotation is linked to before any line is
     * added.
     */
    public function activeTierFor(User $customer): ?PricingTier
    {
        if (! $customer->customerProfile()->where('is_active', true)->exists()) {
            return null;
        }

        return $this->customerSpecificTier($customer) ?? $this->generalTier($customer);
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
            $price = $this->tryTierPrice($variant, $basePrice, $tier, ResolvedPriceSource::ProductScopedTier);

            if ($price instanceof ResolvedPrice) {
                $candidates[] = $price;
            }
        }

        return $candidates;
    }

    /**
     * A tier's discount can be invalid for a given base price (e.g. a fixed discount larger
     * than a zero/negative base price), which {@see PricingTierDiscountCalculator} reports by
     * throwing. Callers treat that as "this tier doesn't apply" rather than a fatal error.
     */
    private function tryTierPrice(ProductVariant $variant, float $basePrice, PricingTier $tier, ResolvedPriceSource $source): ?ResolvedPrice
    {
        try {
            return $this->tierPrice($variant, $basePrice, $tier, $source);
        } catch (DomainException) {
            return null;
        }
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
