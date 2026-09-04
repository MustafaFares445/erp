<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Inventory\ResolvedPrice;
use App\Enums\ResolvedPriceSource;
use App\Models\PriceFloorOverride;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\PriceResolver;
use DomainException;

final readonly class PriceProvenanceService
{
    public function __construct(private PriceResolver $priceResolver) {}

    /**
     * @return array{
     *     resolved_price_source:string,
     *     resolved_price_tier_id:int|null,
     *     price_floor_override_id:int|null,
     *     list_price_minor:int,
     *     floor_price_minor:int|null
     * }
     */
    public function fromResolved(
        ResolvedPrice $resolved,
        float $unitMultiplier = 1.0,
        ?ResolvedPriceSource $sourceOverride = null,
        ?PriceFloorOverride $floorOverride = null,
    ): array {
        return [
            'resolved_price_source' => ($sourceOverride ?? $resolved->source)->value,
            'resolved_price_tier_id' => $resolved->tierId,
            'price_floor_override_id' => $floorOverride?->getKey() === null ? null : (int) $floorOverride->getKey(),
            'list_price_minor' => self::minor($resolved->baseAmount * $unitMultiplier),
            'floor_price_minor' => $resolved->minimumPrice === null
                ? null
                : self::minor($resolved->minimumPrice * $unitMultiplier),
        ];
    }

    /**
     * Resolve the current policy for a manually-entered commercial price while
     * preserving that the operator, not the resolver, chose the final amount.
     *
     * @return array{
     *     resolved_price_source:string,
     *     resolved_price_tier_id:int|null,
     *     price_floor_override_id:int|null,
     *     list_price_minor:int,
     *     floor_price_minor:int|null
     * }
     */
    public function forManualPrice(
        ProductVariant $variant,
        ?User $customer,
        float $unitPrice,
        float $unitMultiplier = 1.0,
        ?int $floorOverrideId = null,
    ): array {
        if ($unitMultiplier <= 0.0) {
            throw new DomainException('Price provenance requires a positive unit conversion factor.');
        }

        $resolved = $this->priceResolver->resolve($variant, $customer);
        $baseEquivalentPrice = $unitPrice / $unitMultiplier;
        $floorOverride = $this->validatedOverride(
            $variant,
            $customer,
            $baseEquivalentPrice,
            $floorOverrideId,
        );

        if ($floorOverride === null) {
            $this->priceResolver->assertAtOrAboveFloor($variant, $baseEquivalentPrice);
        }

        return $this->fromResolved(
            $resolved,
            $unitMultiplier,
            ResolvedPriceSource::ManualOverride,
            $floorOverride,
        );
    }

    private function validatedOverride(
        ProductVariant $variant,
        ?User $customer,
        float $baseEquivalentPrice,
        ?int $overrideId,
    ): ?PriceFloorOverride {
        if ($overrideId === null) {
            return null;
        }

        $override = PriceFloorOverride::query()->find($overrideId);

        if (! $override instanceof PriceFloorOverride
            || $override->approved_at === null
            || (int) $override->product_variant_id !== (int) $variant->getKey()
            || (int) ($override->customer_user_id ?? 0) !== (int) ($customer?->getKey() ?? 0)
            || abs((float) $override->attempted_price - $baseEquivalentPrice) > 0.009) {
            throw new DomainException('The selected price-floor override does not approve this commercial price.');
        }

        return $override;
    }

    private static function minor(float $amount): int
    {
        return max(0, (int) round($amount * 100));
    }
}
