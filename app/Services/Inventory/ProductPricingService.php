<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\PriceFloorOverrideData;
use App\Data\Inventory\VariantPricingData;
use App\Enums\InventoryPermission;
use App\Enums\PriceChangeRequestStatus;
use App\Enums\UserType;
use App\Models\InventorySetting;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class ProductPricingService
{
    public function __construct(
        private PriceResolver $priceResolver,
    ) {}

    public function updateVariantPricing(ProductVariant $variant, VariantPricingData $pricing, User $actor): ProductVariant
    {
        Gate::forUser($actor)->authorize(InventoryPermission::PricingManage->value);
        $this->assertValidVariantPricing($pricing);

        return DB::transaction(
            fn (): ProductVariant => $this->requestVariantPricing($this->lockVariant($variant), $pricing, $actor),
            attempts: 5,
        );
    }

    public function approvePriceChangeRequest(PriceHistory $request, User $actor): PriceHistory
    {
        Gate::forUser($actor)->authorize(InventoryPermission::PricingReview->value);

        return DB::transaction(
            fn (): PriceHistory => $this->applyApproval($this->lockPendingRequest($request), $actor),
            attempts: 5,
        );
    }

    public function rejectPriceChangeRequest(PriceHistory $request, User $actor): PriceHistory
    {
        Gate::forUser($actor)->authorize(InventoryPermission::PricingReview->value);

        return DB::transaction(function () use ($request, $actor): PriceHistory {
            $locked = $this->lockPendingRequest($request);
            $locked->forceFill([
                'status' => PriceChangeRequestStatus::Rejected,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();

            return $locked->refresh();
        }, attempts: 5);
    }

    public function updatePriceChangeRequest(PriceHistory $request, VariantPricingData $pricing, User $actor): PriceHistory
    {
        Gate::forUser($actor)->authorize(InventoryPermission::PricingReview->value);
        $this->assertValidVariantPricing($pricing);

        return DB::transaction(function () use ($request, $pricing, $actor): PriceHistory {
            $locked = $this->lockPendingRequest($request);
            $variant = $this->lockVariant(ProductVariant::query()->findOrFail($locked->product_variant_id));
            $locked->forceFill($this->normalizedPricing($variant, $pricing))->save();

            return $this->applyApproval($locked, $actor);
        }, attempts: 5);
    }

    public function updateCostFromInventory(ProductVariant $variant, float $costPrice, User $actor, ?float $minimumPrice = null): ProductVariant
    {
        $this->assertNonNegative($costPrice, 'Cost price');
        $this->assertNonNegative($minimumPrice, 'Minimum price');

        return DB::transaction(function () use ($variant, $costPrice, $minimumPrice, $actor): ProductVariant {
            $lockedVariant = $this->lockVariant($variant);
            $pricing = new VariantPricingData(
                costPrice: $costPrice,
                markupPercent: $lockedVariant->markup_percent === null ? null : (float) $lockedVariant->markup_percent,
                minimumPrice: $minimumPrice ?? ($lockedVariant->min_price === null ? null : (float) $lockedVariant->min_price),
            );

            return $this->writeVariantPricing($lockedVariant, $pricing, $actor);
        }, attempts: 5);
    }

    public function updateFromInventoryImport(ProductVariant $variant, VariantPricingData $pricing, User $actor): ProductVariant
    {
        $this->assertValidVariantPricing($pricing);

        return DB::transaction(
            fn (): ProductVariant => $this->writeVariantPricing($this->lockVariant($variant), $pricing, $actor),
            attempts: 5,
        );
    }

    public function approveFloorOverride(PriceFloorOverrideData $approval, User $actor): PriceFloorOverride
    {
        Gate::forUser($actor)->authorize(InventoryPermission::PriceFloorApprove->value);
        $reason = Str::squish($approval->reason);

        if ($reason === '') {
            throw new DomainException('A reason is required for a price-floor override.');
        }

        return DB::transaction(
            fn (): PriceFloorOverride => $this->createFloorOverride($approval, $reason, $actor),
            attempts: 5,
        );
    }

    private function writeVariantPricing(ProductVariant $variant, VariantPricingData $pricing, User $actor): ProductVariant
    {
        $oldValues = $this->priceValues($variant);
        $newValues = $this->normalizedPricing($variant, $pricing);

        if ($oldValues === $newValues) {
            return $variant;
        }

        $variant->forceFill($newValues)->save();
        PriceHistory::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            ...$newValues,
            'changed_by' => $actor->getKey(),
        ]);
        activity()
            ->performedOn($variant)
            ->causedBy($actor)
            ->withChanges([
                'old' => $oldValues,
                'attributes' => $newValues,
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('catalog.variant.price_updated');

        return $variant->refresh();
    }

    /**
     * Always records a price change request. Applies it immediately only when
     * the actor can also review pricing requests; otherwise it is left
     * pending for a reviewer to approve, reject, or update.
     */
    private function requestVariantPricing(ProductVariant $variant, VariantPricingData $pricing, User $actor): ProductVariant
    {
        $oldValues = $this->priceValues($variant);
        $newValues = $this->normalizedPricing($variant, $pricing);

        if ($oldValues === $newValues) {
            return $variant;
        }

        $autoApproved = $actor->can(InventoryPermission::PricingReview->value);

        PriceHistory::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            ...$newValues,
            'changed_by' => $actor->getKey(),
            'status' => $autoApproved ? PriceChangeRequestStatus::Approved : PriceChangeRequestStatus::Pending,
            'reviewed_by' => $autoApproved ? $actor->getKey() : null,
            'reviewed_at' => $autoApproved ? now() : null,
        ]);

        if (! $autoApproved) {
            return $variant;
        }

        $variant->forceFill($newValues)->save();
        activity()
            ->performedOn($variant)
            ->causedBy($actor)
            ->withChanges([
                'old' => $oldValues,
                'attributes' => $newValues,
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('catalog.variant.price_updated');

        return $variant->refresh();
    }

    private function lockPendingRequest(PriceHistory $request): PriceHistory
    {
        $locked = PriceHistory::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->status->isPending()) {
            throw new DomainException('Only a pending price change request can be reviewed.');
        }

        return $locked;
    }

    private function applyApproval(PriceHistory $request, User $actor): PriceHistory
    {
        $variant = $this->lockVariant(ProductVariant::query()->findOrFail($request->product_variant_id));
        $oldValues = $this->priceValues($variant);
        $newValues = [
            'cost_price' => $request->cost_price === null ? null : (float) $request->cost_price,
            'base_price' => $request->base_price === null ? null : (float) $request->base_price,
            'min_price' => $request->min_price === null ? null : (float) $request->min_price,
            'markup_percent' => $request->markup_percent === null ? null : (float) $request->markup_percent,
        ];

        $variant->forceFill($newValues)->save();
        $request->forceFill([
            'status' => PriceChangeRequestStatus::Approved,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
        ])->save();

        activity()
            ->performedOn($variant)
            ->causedBy($actor)
            ->withChanges([
                'old' => $oldValues,
                'attributes' => $newValues,
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('catalog.variant.price_change_request_approved');

        return $request->refresh();
    }

    /** @return array{cost_price: float|null, base_price: float|null, min_price: float|null, markup_percent: float} */
    private function normalizedPricing(ProductVariant $variant, VariantPricingData $pricing): array
    {
        $markup = round(
            $pricing->markupPercent
                ?? ($variant->markup_percent === null
                    ? (float) InventorySetting::current()->default_markup_percent
                    : (float) $variant->markup_percent),
            2,
        );
        $cost = $pricing->costPrice === null ? null : round($pricing->costPrice, 2);

        return [
            'cost_price' => $cost,
            'base_price' => $cost === null ? null : round($cost * (1 + ($markup / 100)), 2),
            'min_price' => $pricing->minimumPrice === null ? null : round($pricing->minimumPrice, 2),
            'markup_percent' => $markup,
        ];
    }

    private function createFloorOverride(PriceFloorOverrideData $approval, string $reason, User $actor): PriceFloorOverride
    {
        $variant = $this->lockVariant(ProductVariant::query()->findOrFail($approval->productVariantId));
        $customer = $approval->customerUserId === null
            ? null
            : User::query()->lockForUpdate()->findOrFail($approval->customerUserId);
        $tier = $approval->pricingTierId === null
            ? null
            : PricingTier::query()->lockForUpdate()->findOrFail($approval->pricingTierId);

        if ($customer instanceof User && ($customer->user_type !== UserType::Customer || ! $customer->customerProfile()->where('is_active', true)->exists())) {
            throw new DomainException('Price-floor approvals require an active customer profile.');
        }

        $this->assertTierProvenance($tier, $variant, $customer, $approval->attemptedPrice);

        if ($variant->min_price === null || $approval->attemptedPrice >= (float) $variant->min_price) {
            throw new DomainException(__('admin.inventory.pricing.errors.override_not_required'));
        }

        $override = PriceFloorOverride::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'customer_user_id' => $customer?->getKey(),
            'pricing_tier_id' => $tier?->getKey(),
            'attempted_price' => round($approval->attemptedPrice, 2),
            'min_price' => $variant->min_price,
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
            'reason' => $reason,
        ]);
        activity()
            ->performedOn($override)
            ->causedBy($actor)
            ->withChanges([
                'attributes' => [
                    'product_variant_id' => $override->product_variant_id,
                    'customer_user_id' => $override->customer_user_id,
                    'pricing_tier_id' => $override->pricing_tier_id,
                    'attempted_price' => (float) $override->attempted_price,
                    'min_price' => (float) $override->min_price,
                    'reason' => $override->reason,
                ],
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('catalog.variant.price_floor_overridden');

        return $override;
    }

    private function assertTierProvenance(?PricingTier $tier, ProductVariant $variant, ?User $customer, float $attemptedPrice): void
    {
        $resolved = $this->priceResolver->resolve($variant, $customer);
        $resolvedTierId = $resolved->pricingTier?->getKey();

        if ($tier instanceof PricingTier) {
            if ($resolvedTierId !== $tier->getKey() || round($resolved->amount, 2) !== round($attemptedPrice, 2)) {
                throw new DomainException('The pricing-tier provenance is not the resolved winner for this floor approval.');
            }

            return;
        }

        if ($resolvedTierId !== null && round($resolved->amount, 2) === round($attemptedPrice, 2)) {
            throw new DomainException('Pricing-tier provenance is required for this floor approval.');
        }
    }

    private function lockVariant(ProductVariant $variant): ProductVariant
    {
        $id = $variant->getKey();

        if (! is_int($id)) {
            throw new DomainException('A persisted product variant is required.');
        }

        return ProductVariant::query()->lockForUpdate()->findOrFail($id);
    }

    private function assertValidVariantPricing(VariantPricingData $pricing): void
    {
        $this->assertNonNegative($pricing->costPrice, 'Cost price');
        $this->assertPercentage($pricing->markupPercent, 'Markup percentage');
        $this->assertNonNegative($pricing->minimumPrice, 'Minimum price');
    }

    private function assertNonNegative(?float $amount, string $field): void
    {
        if ($amount !== null && $amount < 0) {
            throw new DomainException($field.' cannot be negative.');
        }
    }

    private function assertPercentage(?float $percentage, string $field): void
    {
        if ($percentage !== null && ($percentage < 0 || $percentage > 100)) {
            throw new DomainException($field.' must be between 0 and 100.');
        }
    }

    /** @return array{cost_price: float|null, base_price: float|null, min_price: float|null, markup_percent: float|null} */
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
