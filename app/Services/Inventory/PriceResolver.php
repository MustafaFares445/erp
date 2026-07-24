<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\ResolvedPrice;
use App\Models\CustomerPricingTier;
use App\Models\InventorySetting;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class PriceResolver
{
    public function __construct(private AuditLogger $auditLogger) {}

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
    public function updateCost(ProductVariant $variant, float $costPrice, User $actor, ?float $minimumPrice = null): void
    {
        DB::transaction(function () use ($variant, $costPrice, $actor, $minimumPrice): void {
            /** @var ProductVariant $locked */
            $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->getKey());
            $markup = $locked->markup_percent ?? InventorySetting::current()->default_markup_percent;
            $basePrice = round($costPrice * (1 + ((float) $markup / 100)), 2);
            $oldValues = $this->priceValues($locked);

            $locked->forceFill([
                'cost_price' => $costPrice,
                'base_price' => $basePrice,
                'min_price' => $minimumPrice ?? $locked->min_price,
            ])->save();

            PriceHistory::query()->forceCreate([
                'product_variant_id' => $locked->getKey(),
                'cost_price' => $locked->cost_price,
                'base_price' => $locked->base_price,
                'min_price' => $locked->min_price,
                'markup_percent' => $markup,
                'changed_by' => $actor->getKey(),
            ]);

            $this->auditLogger->log(
                action: 'catalog.variant.price_updated',
                entity: $locked,
                oldValues: $oldValues,
                newValues: $this->priceValues($locked),
                actor: $actor,
                sourceChannel: 'dashboard',
            );
        }, attempts: 5);
    }

    /** @throws DomainException */
    public function assertAtOrAboveFloor(ProductVariant $variant, float $price): void
    {
        if ($variant->min_price !== null && $price < (float) $variant->min_price) {
            throw new DomainException(__('admin.inventory.pricing.errors.below_floor'));
        }
    }

    /** @throws DomainException */
    public function overrideFloor(ProductVariant $variant, ?User $customer, float $attemptedPrice, string $reason, User $actor): PriceFloorOverride
    {
        if (! $actor->isAdmin()) {
            throw new DomainException(__('admin.inventory.pricing.errors.override_unauthorized'));
        }

        if ($variant->min_price === null || $attemptedPrice >= (float) $variant->min_price) {
            throw new DomainException(__('admin.inventory.pricing.errors.override_not_required'));
        }

        return DB::transaction(function () use ($variant, $customer, $attemptedPrice, $reason, $actor): PriceFloorOverride {
            $override = PriceFloorOverride::query()->forceCreate([
                'product_variant_id' => $variant->getKey(),
                'customer_user_id' => $customer?->getKey(),
                'attempted_price' => $attemptedPrice,
                'min_price' => $variant->min_price,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'reason' => $reason,
            ]);

            $this->auditLogger->log(
                action: 'catalog.variant.price_floor_overridden',
                entity: $override,
                newValues: ['product_variant_id' => $variant->getKey(), 'attempted_price' => $attemptedPrice, 'reason' => $reason],
                actor: $actor,
                sourceChannel: 'dashboard',
            );

            return $override;
        }, attempts: 5);
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
            ->withWhereHas('pricingTier', fn ($query) => $query->whereNull('customer_user_id')->where('is_active', true))
            ->orderBy('id')
            ->first()
            ?->pricingTier;
    }

    /** @return array<string, float|null> */
    private function priceValues(ProductVariant $variant): array
    {
        return [
            'cost_price' => $variant->cost_price === null ? null : (float) $variant->cost_price,
            'base_price' => $variant->base_price === null ? null : (float) $variant->base_price,
            'min_price' => $variant->min_price === null ? null : (float) $variant->min_price,
            'markup_percent' => $variant->markup_percent === null ? null : (float) $variant->markup_percent,
        ];
    }
}
