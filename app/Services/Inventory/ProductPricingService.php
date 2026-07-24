<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\PriceFloorOverrideData;
use App\Data\Inventory\PricingTierData;
use App\Data\Inventory\VariantPricingData;
use App\Enums\InventoryPermission;
use App\Enums\UserType;
use App\Models\CustomerPricingTier;
use App\Models\InventorySetting;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class ProductPricingService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function updateVariantPricing(
        ProductVariant $variant,
        VariantPricingData $pricing,
        User $actor,
    ): ProductVariant {
        $this->authorizePricingManagement($actor);
        $this->assertValidVariantPricing($pricing);

        return DB::transaction(
            fn (): ProductVariant => $this->writeVariantPricing($this->lockVariant($variant), $pricing, $actor),
            attempts: 5,
        );
    }

    public function updateCostFromInventory(
        ProductVariant $variant,
        float $costPrice,
        User $actor,
        ?float $minimumPrice = null,
    ): ProductVariant {
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

    public function saveTier(?PricingTier $tier, PricingTierData $pricingTier, User $actor): PricingTier
    {
        $this->authorizePricingManagement($actor);

        return DB::transaction(function () use ($tier, $pricingTier, $actor): PricingTier {
            $lockedTier = $tier instanceof PricingTier ? $this->lockTier($tier) : new PricingTier;
            $customer = $this->lockCustomer($pricingTier->customerUserId);
            $this->assertValidTier($pricingTier, $customer);

            return $this->persistTier($lockedTier, $pricingTier, $customer, $actor);
        }, attempts: 5);
    }

    public function assignGeneralTier(User $customer, PricingTier $pricingTier, User $actor): CustomerPricingTier
    {
        $this->authorizePricingManagement($actor);
        $this->assertCustomer($customer);

        return DB::transaction(
            fn (): CustomerPricingTier => $this->assignLockedGeneralTier($customer, $pricingTier, $actor),
            attempts: 5,
        );
    }

    public function deleteTier(PricingTier $tier, User $actor): bool
    {
        $this->authorizePricingManagement($actor);

        return DB::transaction(function () use ($tier, $actor): bool {
            $lockedTier = $this->lockTier($tier);

            if ($lockedTier->trashed()) {
                return false;
            }

            $oldValues = $this->tierValues($lockedTier);
            $deleted = (bool) $lockedTier->delete();

            if ($deleted) {
                $this->auditLogger->log(
                    action: 'pricing.tier.deleted',
                    entity: $lockedTier,
                    oldValues: $oldValues,
                    actor: $actor,
                    sourceChannel: 'dashboard',
                );
            }

            return $deleted;
        }, attempts: 5);
    }

    public function restoreTier(PricingTier $tier, User $actor): bool
    {
        $this->authorizePricingManagement($actor);

        return DB::transaction(function () use ($tier, $actor): bool {
            $lockedTier = $this->lockTier($tier);

            if (! $lockedTier->trashed()) {
                return false;
            }

            $customer = $this->lockCustomer($lockedTier->customer_user_id);

            if ($customer instanceof User && $lockedTier->is_active) {
                $this->deactivateOtherSpecificTiers($customer, $lockedTier, $actor);
            }

            return $this->restoreLockedTier($lockedTier, $actor);
        }, attempts: 5);
    }

    public function approveFloorOverride(PriceFloorOverrideData $approval, User $actor): PriceFloorOverride
    {
        $this->authorizePricingManagement($actor);
        $normalizedReason = Str::squish($approval->reason);

        if ($normalizedReason === '') {
            throw new DomainException('A reason is required for a price-floor override.');
        }

        return DB::transaction(
            fn (): PriceFloorOverride => $this->createFloorOverride($approval, $normalizedReason, $actor),
            attempts: 5,
        );
    }

    private function writeVariantPricing(
        ProductVariant $variant,
        VariantPricingData $pricing,
        User $actor,
    ): ProductVariant {
        $oldValues = $this->priceValues($variant);
        $newValues = $this->normalizedPricing($variant, $pricing);

        if ($oldValues === $newValues) {
            return $variant;
        }

        $variant->forceFill($newValues)->save();
        $this->recordVariantPricingChange($variant, $oldValues, $newValues, $actor);

        return $variant->refresh();
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

    /**
     * @param  array{cost_price: float|null, base_price: float|null, min_price: float|null, markup_percent: float|null}  $oldValues
     * @param  array{cost_price: float|null, base_price: float|null, min_price: float|null, markup_percent: float}  $newValues
     */
    private function recordVariantPricingChange(
        ProductVariant $variant,
        array $oldValues,
        array $newValues,
        User $actor,
    ): void {
        PriceHistory::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            ...$newValues,
            'changed_by' => $actor->getKey(),
        ]);

        $this->auditLogger->log(
            action: 'catalog.variant.price_updated',
            entity: $variant,
            oldValues: $oldValues,
            newValues: $newValues,
            actor: $actor,
            sourceChannel: 'dashboard',
        );
    }

    private function persistTier(
        PricingTier $tier,
        PricingTierData $pricingTier,
        ?User $customer,
        User $actor,
    ): PricingTier {
        if ($customer instanceof User && $pricingTier->isActive) {
            $this->deactivateOtherSpecificTiers($customer, $tier, $actor);
        }

        $oldValues = $tier->exists ? $this->tierValues($tier) : null;
        $tier->forceFill([
            'name' => Str::squish($pricingTier->name),
            'discount_percent' => round($pricingTier->discountPercent, 2),
            'customer_user_id' => $customer?->getKey(),
            'is_active' => $pricingTier->isActive,
        ]);

        if (! $tier->isDirty()) {
            return $tier;
        }

        $tier->save();
        $this->auditTierChange($tier, $oldValues, $actor);

        return $tier->refresh();
    }

    /** @param array{name: string, discount_percent: float, customer_user_id: int|null, is_active: bool}|null $oldValues */
    private function auditTierChange(PricingTier $tier, ?array $oldValues, User $actor): void
    {
        $this->auditLogger->log(
            action: $oldValues === null ? 'pricing.tier.created' : 'pricing.tier.updated',
            entity: $tier,
            oldValues: $oldValues,
            newValues: $this->tierValues($tier),
            actor: $actor,
            sourceChannel: 'dashboard',
        );
    }

    private function assignLockedGeneralTier(
        User $customer,
        PricingTier $pricingTier,
        User $actor,
    ): CustomerPricingTier {
        User::query()->lockForUpdate()->findOrFail($customer->id);
        $lockedTier = $this->lockEligibleGeneralTier($pricingTier);
        $assignments = CustomerPricingTier::query()
            ->where('customer_user_id', $customer->id)
            ->lockForUpdate()
            ->get();
        $deactivatedIds = $this->deactivateOtherAssignments($assignments, $lockedTier);
        $assignment = $this->activateAssignment($assignments, $customer, $lockedTier);

        if ($deactivatedIds !== [] || $assignment->wasRecentlyCreated || $assignment->wasChanged('is_active')) {
            $this->auditTierAssignment($assignment, $lockedTier, $deactivatedIds, $actor);
        }

        return $assignment->refresh();
    }

    private function lockEligibleGeneralTier(PricingTier $pricingTier): PricingTier
    {
        $lockedTier = PricingTier::query()->lockForUpdate()->findOrFail($this->tierId($pricingTier));

        if (! $lockedTier->is_active || $lockedTier->customer_user_id !== null) {
            throw new DomainException('Only active general pricing tiers can be assigned.');
        }

        return $lockedTier;
    }

    /**
     * @param  Collection<int, CustomerPricingTier>  $assignments
     * @return list<int>
     */
    private function deactivateOtherAssignments(Collection $assignments, PricingTier $pricingTier): array
    {
        $deactivatedIds = [];

        foreach ($assignments as $assignment) {
            if ($assignment->pricing_tier_id === $pricingTier->id || ! $assignment->is_active) {
                continue;
            }

            $assignment->forceFill(['is_active' => false])->save();
            $deactivatedIds[] = $assignment->id;
        }

        return $deactivatedIds;
    }

    /** @param Collection<int, CustomerPricingTier> $assignments */
    private function activateAssignment(
        Collection $assignments,
        User $customer,
        PricingTier $pricingTier,
    ): CustomerPricingTier {
        $assignment = $assignments->firstWhere('pricing_tier_id', $pricingTier->id)
            ?? new CustomerPricingTier([
                'customer_user_id' => $customer->id,
                'pricing_tier_id' => $pricingTier->id,
            ]);
        $assignment->forceFill(['is_active' => true]);

        if (! $assignment->exists || $assignment->isDirty()) {
            $assignment->save();
        }

        return $assignment;
    }

    /** @param list<int> $deactivatedIds */
    private function auditTierAssignment(
        CustomerPricingTier $assignment,
        PricingTier $pricingTier,
        array $deactivatedIds,
        User $actor,
    ): void {
        $this->auditLogger->log(
            action: 'pricing.customer_tier.assigned',
            entity: $assignment,
            newValues: [
                'customer_user_id' => $assignment->customer_user_id,
                'pricing_tier_id' => $pricingTier->id,
                'deactivated_assignment_ids' => $deactivatedIds,
            ],
            actor: $actor,
            sourceChannel: 'dashboard',
        );
    }

    private function createFloorOverride(
        PriceFloorOverrideData $approval,
        string $reason,
        User $actor,
    ): PriceFloorOverride {
        $variant = ProductVariant::query()->lockForUpdate()->findOrFail($approval->productVariantId);
        $customer = $this->lockCustomer($approval->customerUserId);

        if ($customer instanceof User) {
            $this->assertCustomer($customer);
        }

        if ($variant->min_price === null || $approval->attemptedPrice >= (float) $variant->min_price) {
            throw new DomainException(__('admin.inventory.pricing.errors.override_not_required'));
        }

        $override = PriceFloorOverride::query()->forceCreate([
            'product_variant_id' => $variant->id,
            'customer_user_id' => $customer?->id,
            'attempted_price' => round($approval->attemptedPrice, 2),
            'min_price' => $variant->min_price,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'reason' => $reason,
        ]);
        $this->auditFloorOverride($override, $actor);

        return $override;
    }

    private function auditFloorOverride(PriceFloorOverride $override, User $actor): void
    {
        $this->auditLogger->log(
            action: 'catalog.variant.price_floor_overridden',
            entity: $override,
            newValues: [
                'product_variant_id' => $override->product_variant_id,
                'customer_user_id' => $override->customer_user_id,
                'attempted_price' => (float) $override->attempted_price,
                'min_price' => (float) $override->min_price,
                'reason' => $override->reason,
            ],
            actor: $actor,
            sourceChannel: 'dashboard',
        );
    }

    private function restoreLockedTier(PricingTier $tier, User $actor): bool
    {
        $restored = (bool) $tier->restore();

        if ($restored) {
            $this->auditLogger->log(
                action: 'pricing.tier.restored',
                entity: $tier,
                newValues: $this->tierValues($tier),
                actor: $actor,
                sourceChannel: 'dashboard',
            );
        }

        return $restored;
    }

    private function deactivateOtherSpecificTiers(User $customer, PricingTier $currentTier, User $actor): void
    {
        $otherTiers = PricingTier::query()
            ->where('customer_user_id', $customer->id)
            ->where('is_active', true)
            ->when($currentTier->exists, fn ($query) => $query->whereKeyNot($currentTier->getKey()))
            ->lockForUpdate()
            ->get();

        foreach ($otherTiers as $otherTier) {
            $oldValues = $this->tierValues($otherTier);
            $otherTier->forceFill(['is_active' => false])->save();
            $this->auditLogger->log(
                action: 'pricing.tier.deactivated',
                entity: $otherTier,
                oldValues: $oldValues,
                newValues: $this->tierValues($otherTier),
                actor: $actor,
                sourceChannel: 'dashboard',
            );
        }
    }

    private function lockVariant(ProductVariant $variant): ProductVariant
    {
        return ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
    }

    private function lockTier(PricingTier $tier): PricingTier
    {
        return PricingTier::query()->withTrashed()->lockForUpdate()->findOrFail($this->tierId($tier));
    }

    private function lockCustomer(?int $customerId): ?User
    {
        return $customerId === null
            ? null
            : User::query()->lockForUpdate()->findOrFail($customerId);
    }

    private function authorizePricingManagement(User $actor): void
    {
        Gate::forUser($actor)->authorize(InventoryPermission::PricingManage->value);
    }

    private function tierId(PricingTier $tier): int
    {
        $tierId = $tier->getKey();

        if (! is_int($tierId)) {
            throw new DomainException('A persisted pricing tier is required.');
        }

        return $tierId;
    }

    private function assertValidVariantPricing(VariantPricingData $pricing): void
    {
        $this->assertNonNegative($pricing->costPrice, 'Cost price');
        $this->assertPercentage($pricing->markupPercent, 'Markup percentage');
        $this->assertNonNegative($pricing->minimumPrice, 'Minimum price');
    }

    private function assertValidTier(PricingTierData $pricingTier, ?User $customer): void
    {
        if (Str::squish($pricingTier->name) === '') {
            throw new DomainException('A pricing tier name is required.');
        }

        $this->assertPercentage($pricingTier->discountPercent, 'Discount percentage');

        if ($customer instanceof User) {
            $this->assertCustomer($customer);
        }
    }

    private function assertCustomer(User $customer): void
    {
        if ($customer->user_type !== UserType::Customer) {
            throw new DomainException('Pricing tiers can only be assigned to customer accounts.');
        }
    }

    private function assertNonNegative(?float $amount, string $field): void
    {
        if ($amount !== null && $amount < 0) {
            throw new DomainException("{$field} cannot be negative.");
        }
    }

    private function assertPercentage(?float $percentage, string $field): void
    {
        if ($percentage !== null && ($percentage < 0 || $percentage > 100)) {
            throw new DomainException("{$field} must be between 0 and 100.");
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

    /** @return array{name: string, discount_percent: float, customer_user_id: int|null, is_active: bool} */
    private function tierValues(PricingTier $tier): array
    {
        return [
            'name' => $tier->name,
            'discount_percent' => (float) $tier->discount_percent,
            'customer_user_id' => $tier->customer_user_id,
            'is_active' => $tier->is_active,
        ];
    }
}
