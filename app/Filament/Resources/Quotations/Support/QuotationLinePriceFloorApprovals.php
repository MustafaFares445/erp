<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Support;

use App\Data\Inventory\PriceFloorOverrideData;
use App\Enums\InventoryPermission;
use App\Models\CustomerProfile;
use App\Models\PriceFloorOverride;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\User;
use App\Services\Inventory\PriceResolver;
use App\Services\Inventory\ProductPricingService;
use App\Services\Sales\PriceProvenanceService;
use App\Services\Sales\QuotationService;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a `price_floor_override_id` for every quotation line whose
 * manually entered price is below the variant's floor, before the raw form
 * data reaches {@see QuotationService}.
 *
 * Mirrors the self-approve pattern already used for the discount ceiling in
 * `PricingTierResource`: an actor who holds
 * {@see InventoryPermission::PriceFloorApprove} mints and spends the
 * approval in the same save by supplying a reason; anyone else is left
 * without one, so {@see PriceProvenanceService} still refuses the line
 * exactly as it does today (FR-016) — this class only adds a path past that
 * refusal for someone authorized to grant it.
 */
final readonly class QuotationLinePriceFloorApprovals
{
    public function __construct(
        private PriceResolver $priceResolver,
        private ProductPricingService $pricingService,
    ) {}

    /**
     * @param  array<array-key, mixed>  $rawLines
     * @return list<mixed>
     */
    public function resolve(array $rawLines, mixed $customerId, ?User $actor): array
    {
        $customer = is_numeric($customerId)
            ? CustomerProfile::find((int) $customerId)?->user
            : null;

        return array_values(array_map(
            fn (mixed $rawLine): mixed => is_array($rawLine)
                ? [...$rawLine, 'price_floor_override_id' => $this->resolveLine($rawLine, $customer, $actor)]
                : $rawLine,
            $rawLines,
        ));
    }

    /** @param array<array-key, mixed> $rawLine */
    private function resolveLine(array $rawLine, ?User $customer, ?User $actor): ?int
    {
        $variantId = $rawLine['product_variant_id'] ?? null;
        $unitPrice = $rawLine['unit_price'] ?? null;

        if (! is_numeric($variantId) || ! is_numeric($unitPrice)) {
            return null;
        }

        $variant = ProductVariant::find((int) $variantId);

        if (! $variant instanceof ProductVariant || $variant->min_price === null) {
            return null;
        }

        $baseEquivalentPrice = $this->baseEquivalentPrice((float) $unitPrice, $rawLine['unit_id'] ?? null, $variant);

        if ($baseEquivalentPrice === null || $baseEquivalentPrice >= (float) $variant->min_price) {
            return null;
        }

        $standingId = $rawLine['price_floor_override_id'] ?? null;

        if (is_numeric($standingId)) {
            $standing = PriceFloorOverride::query()->find((int) $standingId);

            if ($standing instanceof PriceFloorOverride && $this->covers($standing, $variant, $customer, $baseEquivalentPrice)) {
                return $this->intKey($standing);
            }
        }

        $reason = $rawLine['price_floor_override_reason'] ?? null;

        if (! $actor instanceof User
            || ! is_string($reason)
            || mb_trim($reason) === ''
            || ! $actor->can(InventoryPermission::PriceFloorApprove->value)) {
            return null;
        }

        $resolved = $this->priceResolver->resolve($variant, $customer);
        $resolvedTierId = $resolved->pricingTier instanceof PricingTier
            ? $this->intKey($resolved->pricingTier)
            : null;
        $tierId = $resolvedTierId !== null && round($resolved->amount, 2) === round($baseEquivalentPrice, 2)
            ? $resolvedTierId
            : null;

        $override = $this->pricingService->approveFloorOverride(
            new PriceFloorOverrideData(
                productVariantId: $this->intKey($variant),
                customerUserId: $customer instanceof User ? $this->intKey($customer) : null,
                attemptedPrice: $baseEquivalentPrice,
                reason: $reason,
                pricingTierId: $tierId,
            ),
            $actor,
        );

        return $this->intKey($override);
    }

    private function intKey(Model $model): int
    {
        $key = $model->getKey();

        if (! is_int($key)) {
            throw new DomainException('A persisted record is required.');
        }

        return $key;
    }

    private function covers(PriceFloorOverride $override, ProductVariant $variant, ?User $customer, float $baseEquivalentPrice): bool
    {
        return $override->product_variant_id === $variant->getKey()
            && $override->customer_user_id === $customer?->getKey()
            && abs((float) $override->attempted_price - $baseEquivalentPrice) <= 0.009;
    }

    private function baseEquivalentPrice(float $unitPrice, mixed $unitId, ProductVariant $variant): ?float
    {
        $factorToBase = is_numeric($unitId)
            ? ProductVariantUnit::query()
                ->where('product_variant_id', $variant->getKey())
                ->where('unit_id', (int) $unitId)
                ->value('factor_to_base')
            : null;

        $factor = is_numeric($factorToBase) ? (float) $factorToBase : 1.0;

        return $factor > 0.0 ? $unitPrice / $factor : null;
    }
}
