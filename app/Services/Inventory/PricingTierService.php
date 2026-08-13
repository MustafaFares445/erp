<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\PricingTierData;
use App\Enums\CrmPermission;
use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\PricingTierVisibility;
use App\Enums\ProductStatus;
use App\Enums\UserType;
use App\Models\CustomerPricingTier;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class PricingTierService
{
    public function save(?PricingTier $tier, PricingTierData $data, User $actor): PricingTier
    {
        return DB::transaction(function () use ($tier, $data, $actor): PricingTier {
            $lockedTier = $tier instanceof PricingTier ? $this->lockTier($tier) : new PricingTier;
            $values = $this->validatedValues($lockedTier, $data);
            $this->authorizeSave($lockedTier, $values, $actor);
            $this->assertUniqueName($values['name'], $lockedTier);

            if ($values['tier_type'] === PricingTierType::CustomerSpecific && $values['is_active'] && is_int($values['customer_user_id'])) {
                $this->deactivateOtherSpecificTiers($values['customer_user_id'], $lockedTier, $actor);
            }

            $oldValues = $lockedTier->exists ? $this->tierValues($lockedTier) : null;
            $lockedTier->forceFill([
                ...$values,
                'created_by' => $lockedTier->exists ? $lockedTier->created_by : $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            if (! $lockedTier->isDirty()) {
                return $lockedTier;
            }

            try {
                $lockedTier->save();
            } catch (QueryException $queryException) {
                if ($this->isUniqueConstraintViolation($queryException)) {
                    $code = $queryException->getCode();

                    throw new DomainException(
                        'A pricing tier with this name already exists.',
                        is_int($code) ? $code : 0,
                        previous: $queryException,
                    );
                }

                throw $queryException;
            }

            activity()
                ->performedOn($lockedTier)
                ->causedBy($actor)
                ->withChanges($oldValues === null
                    ? ['attributes' => $this->tierValues($lockedTier)]
                    : ['old' => $oldValues, 'attributes' => $this->tierValues($lockedTier)])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log($oldValues === null ? 'pricing.tier.created' : 'pricing.tier.updated');

            return $lockedTier->refresh();
        }, attempts: 5);
    }

    /** @param list<int> $productIds */
    public function syncProducts(PricingTier $tier, array $productIds, User $actor): PricingTier
    {
        $this->authorize($actor, CrmPermission::PricingTierLinkManage, InventoryPermission::PricingManage);

        return DB::transaction(function () use ($tier, $productIds, $actor): PricingTier {
            $lockedTier = $this->lockTier($tier);
            $this->assertProductScoped($lockedTier);
            $activeProductIds = $this->activeProductIds($productIds);
            $oldProductIds = array_values($lockedTier->products()
                ->get(['products.id'])
                ->map(static fn (Product $product): int => $product->id)
                ->all());

            $lockedTier->products()->sync($activeProductIds);
            $lockedTier->forceFill(['updated_by' => $actor->getKey()])->save();
            $this->auditRelationshipChange('pricing.tier.products.synchronized', $lockedTier, 'product_ids', $oldProductIds, $activeProductIds, $actor);

            return $lockedTier->load('products');
        }, attempts: 5);
    }

    /** @param list<int> $customerUserIds */
    public function syncCustomers(PricingTier $tier, array $customerUserIds, User $actor): PricingTier
    {
        $this->authorize($actor, CrmPermission::PricingTierLinkManage, InventoryPermission::PricingManage);

        return DB::transaction(function () use ($tier, $customerUserIds, $actor): PricingTier {
            $lockedTier = $this->lockTier($tier);
            $this->assertProductScoped($lockedTier);
            $eligibleCustomerIds = $this->eligibleCustomerIds($customerUserIds);
            $assignments = CustomerPricingTier::query()
                ->where('pricing_tier_id', $lockedTier->getKey())
                ->lockForUpdate()
                ->get()
                ->keyBy('customer_user_id');
            $oldCustomerIds = array_values($assignments
                ->where('is_active', true)
                ->values()
                ->map(static fn (CustomerPricingTier $assignment): int => $assignment->customer_user_id)
                ->all());

            foreach ($assignments as $assignment) {
                $assignment->forceFill(['is_active' => in_array($assignment->customer_user_id, $eligibleCustomerIds, true)]);

                if ($assignment->isDirty()) {
                    $assignment->save();
                }
            }

            foreach ($eligibleCustomerIds as $customerId) {
                if ($assignments->has($customerId)) {
                    continue;
                }

                CustomerPricingTier::query()->create([
                    'customer_user_id' => $customerId,
                    'pricing_tier_id' => $lockedTier->getKey(),
                    'is_active' => true,
                ]);
            }

            $lockedTier->forceFill(['updated_by' => $actor->getKey()])->save();
            $this->auditRelationshipChange('pricing.tier.customers.synchronized', $lockedTier, 'customer_user_ids', $oldCustomerIds, $eligibleCustomerIds, $actor);

            return $lockedTier->load('assignments.customer');
        }, attempts: 5);
    }

    public function assignGeneralTier(User $customer, PricingTier $tier, User $actor): CustomerPricingTier
    {
        $this->authorize($actor, CrmPermission::PricingTierLinkManage, InventoryPermission::PricingManage);

        return DB::transaction(function () use ($customer, $tier, $actor): CustomerPricingTier {
            $this->assertEligibleCustomer($customer);
            User::query()->lockForUpdate()->findOrFail($customer->getKey());
            $lockedTier = $this->lockTier($tier);

            if ($lockedTier->tier_type !== PricingTierType::General || ! $lockedTier->is_active) {
                throw new DomainException('Only active general pricing tiers can be assigned as the general tier.');
            }

            CustomerPricingTier::query()
                ->where('customer_user_id', $customer->getKey())
                ->where('is_active', true)
                ->whereHas('pricingTier', fn (Builder $query): Builder => $query->where('tier_type', PricingTierType::General->value))
                ->where('pricing_tier_id', '!=', $lockedTier->getKey())
                ->lockForUpdate()
                ->update(['is_active' => false, 'updated_at' => now()]);

            $assignment = CustomerPricingTier::query()->firstOrNew([
                'customer_user_id' => $customer->getKey(),
                'pricing_tier_id' => $lockedTier->getKey(),
            ]);
            $assignment->forceFill(['is_active' => true])->save();

            activity()
                ->performedOn($assignment)
                ->causedBy($actor)
                ->withChanges([
                    'attributes' => ['customer_user_id' => $customer->getKey(), 'pricing_tier_id' => $lockedTier->getKey()],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('pricing.tier.general.assigned');

            return $assignment->refresh();
        }, attempts: 5);
    }

    public function activate(PricingTier $tier, User $actor): PricingTier
    {
        $this->authorize($actor, CrmPermission::PricingTierManage, InventoryPermission::PricingManage);

        return DB::transaction(function () use ($tier, $actor): PricingTier {
            $lockedTier = $this->lockTier($tier);
            $this->assertActivationEligibility($lockedTier);
            $oldValues = $this->tierValues($lockedTier);
            $lockedTier->forceFill(['is_active' => true, 'updated_by' => $actor->getKey()])->save();
            $this->auditStateChange('pricing.tier.activated', $lockedTier, $oldValues, $actor);

            return $lockedTier->refresh();
        }, attempts: 5);
    }

    public function deactivate(PricingTier $tier, User $actor): PricingTier
    {
        $this->authorize($actor, CrmPermission::PricingTierManage, InventoryPermission::PricingManage);

        return DB::transaction(function () use ($tier, $actor): PricingTier {
            $lockedTier = $this->lockTier($tier);
            $oldValues = $this->tierValues($lockedTier);
            $lockedTier->forceFill(['is_active' => false, 'updated_by' => $actor->getKey()])->save();
            $this->auditStateChange('pricing.tier.deactivated', $lockedTier, $oldValues, $actor);

            return $lockedTier->refresh();
        }, attempts: 5);
    }

    public function delete(PricingTier $tier, User $actor): bool
    {
        $this->authorize($actor, CrmPermission::PricingTierManage, InventoryPermission::PricingManage);

        return DB::transaction(function () use ($tier, $actor): bool {
            $lockedTier = $this->lockTier($tier);
            $oldValues = $this->tierValues($lockedTier);
            $lockedTier->forceFill(['is_active' => false, 'updated_by' => $actor->getKey()])->save();
            $deleted = (bool) $lockedTier->delete();

            if ($deleted) {
                $this->auditStateChange('pricing.tier.deleted', $lockedTier, $oldValues, $actor);
            }

            return $deleted;
        }, attempts: 5);
    }

    public function restore(PricingTier $tier, User $actor): PricingTier
    {
        $this->authorize($actor, CrmPermission::PricingTierRestore, InventoryPermission::PricingManage);

        return DB::transaction(function () use ($tier, $actor): PricingTier {
            $lockedTier = $this->lockTier($tier);

            if (! $lockedTier->trashed()) {
                return $lockedTier;
            }

            $lockedTier->forceFill([
                'is_active' => $lockedTier->tier_type === PricingTierType::ProductScoped ? false : $lockedTier->is_active,
                'updated_by' => $actor->getKey(),
            ])->save();
            $lockedTier->restore();
            activity()
                ->performedOn($lockedTier)
                ->causedBy($actor)
                ->withChanges(['attributes' => $this->tierValues($lockedTier)])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('pricing.tier.restored');

            return $lockedTier->refresh();
        }, attempts: 5);
    }

    /**
     * @return array{name: string, tier_type: PricingTierType, discount_type: PricingTierDiscountType, discount_value: float, customer_user_id: int|null, visibility: PricingTierVisibility|null, valid_from: string|null, valid_until: string|null, is_active: bool}
     */
    private function validatedValues(PricingTier $tier, PricingTierData $data): array
    {
        $name = Str::squish($data->name);

        if ($name === '') {
            throw new DomainException('A pricing tier name is required.');
        }

        if ($data->discountType === PricingTierDiscountType::Percentage && ($data->discountValue < 0 || $data->discountValue > 100)) {
            throw new DomainException('A percentage discount must be between 0 and 100.');
        }

        if ($data->discountType === PricingTierDiscountType::Fixed && $data->discountValue <= 0) {
            throw new DomainException('A fixed discount must be greater than zero.');
        }

        if ($data->tierType !== PricingTierType::ProductScoped && $data->discountType !== PricingTierDiscountType::Percentage) {
            throw new DomainException('Only product-scoped pricing tiers may use fixed discounts.');
        }

        $customerId = $data->tierType === PricingTierType::CustomerSpecific ? $data->customerUserId : null;

        if ($data->tierType === PricingTierType::CustomerSpecific) {
            if ($customerId === null) {
                throw new DomainException('A customer-specific pricing tier requires a customer.');
            }

            $this->assertEligibleCustomer(User::query()->findOrFail($customerId));
        }

        $visibility = $data->tierType === PricingTierType::ProductScoped ? $data->visibility : null;

        if ($data->tierType === PricingTierType::ProductScoped && ! $visibility instanceof PricingTierVisibility) {
            throw new DomainException('A product-scoped pricing tier requires visibility.');
        }

        $validFrom = $data->tierType === PricingTierType::ProductScoped && $data->validFrom !== null
            ? CarbonImmutable::parse($data->validFrom)->toDateString()
            : null;
        $validUntil = $data->tierType === PricingTierType::ProductScoped && $data->validUntil !== null
            ? CarbonImmutable::parse($data->validUntil)->toDateString()
            : null;

        if ($validFrom !== null && $validUntil !== null && $validUntil < $validFrom) {
            throw new DomainException('The validity end date cannot precede the start date.');
        }

        if ($tier->exists) {
            $this->assertTypeChangeDoesNotOrphanRelationships($tier, $data->tierType);
        }

        if ($data->isActive) {
            $tier->forceFill([
                'tier_type' => $data->tierType,
                'discount_type' => $data->discountType,
                'discount_value' => $data->discountValue,
                'visibility' => $visibility,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
            ]);
            $this->assertActivationEligibility($tier);
        }

        return [
            'name' => $name,
            'tier_type' => $data->tierType,
            'discount_type' => $data->discountType,
            'discount_value' => round($data->discountValue, 2),
            'customer_user_id' => $customerId,
            'visibility' => $visibility,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'is_active' => $data->isActive,
        ];
    }

    private function assertActivationEligibility(PricingTier $tier): void
    {
        if ($tier->tier_type !== PricingTierType::ProductScoped) {
            return;
        }

        if (! $tier->exists || ! $tier->products()->where('is_active', true)->where('status', ProductStatus::Active->value)->exists()) {
            throw new DomainException('A product-scoped pricing tier requires at least one active product before activation.');
        }

        if ($tier->visibility === PricingTierVisibility::Restricted && ! $tier->assignments()
            ->where('is_active', true)
            ->whereHas('customer.customerProfile', fn (Builder $query): Builder => $query->where('is_active', true))
            ->exists()) {
            throw new DomainException('A restricted product-scoped pricing tier requires an active customer assignment before activation.');
        }

        if ($tier->discount_type === PricingTierDiscountType::Fixed) {
            $discountValue = (float) $tier->discount_value;
            $hasNonPositiveVariant = $tier->products()
                ->whereHas('variants', fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->where('status', ProductStatus::Active->value)
                    ->whereNotNull('base_price')
                    ->where('base_price', '<=', $discountValue))
                ->exists();

            if ($hasNonPositiveVariant) {
                throw new DomainException('The fixed discount must leave a positive price for every active linked variant.');
            }
        }
    }

    private function assertTypeChangeDoesNotOrphanRelationships(PricingTier $tier, PricingTierType $newType): void
    {
        if ($tier->tier_type === $newType) {
            return;
        }

        if ($tier->products()->exists()) {
            throw new DomainException('Remove product links before changing the pricing tier type.');
        }

        if ($tier->assignments()->where('is_active', true)->exists()) {
            throw new DomainException('Remove active customer assignments before changing the pricing tier type.');
        }
    }

    /** @param list<int> $productIds
     * @return list<int>
     */
    private function activeProductIds(array $productIds): array
    {
        $this->assertUniqueIds($productIds, 'product links');

        if ($productIds === []) {
            return [];
        }

        $ids = array_values(Product::query()
            ->whereKey($productIds)
            ->where('is_active', true)
            ->where('status', ProductStatus::Active->value)
            ->get(['id'])
            ->map(static fn (Product $product): int => $product->id)
            ->all());

        if (count($ids) !== count($productIds)) {
            throw new DomainException('Pricing tiers can only link active products.');
        }

        return $ids;
    }

    /** @param list<int> $customerIds
     * @return list<int>
     */
    private function eligibleCustomerIds(array $customerIds): array
    {
        $this->assertUniqueIds($customerIds, 'customer assignments');

        if ($customerIds === []) {
            return [];
        }

        $ids = array_values(User::query()
            ->whereKey($customerIds)
            ->where('user_type', UserType::Customer->value)
            ->whereHas('customerProfile', fn (Builder $query): Builder => $query->where('is_active', true))
            ->get(['id'])
            ->map(static fn (User $customer): int => $customer->id)
            ->all());

        if (count($ids) !== count($customerIds)) {
            throw new DomainException('Pricing tiers can only be assigned to active customer profiles.');
        }

        return $ids;
    }

    private function assertEligibleCustomer(User $customer): void
    {
        if ($customer->user_type !== UserType::Customer || ! $customer->customerProfile()->where('is_active', true)->exists()) {
            throw new DomainException('Pricing tiers can only be assigned to active customer profiles.');
        }
    }

    private function assertProductScoped(PricingTier $tier): void
    {
        if ($tier->tier_type !== PricingTierType::ProductScoped) {
            throw new DomainException('Product and customer links are available only for product-scoped pricing tiers.');
        }
    }

    /**
     * @param  array{name: string, tier_type: PricingTierType, discount_type: PricingTierDiscountType, discount_value: float, customer_user_id: int|null, visibility: PricingTierVisibility|null, valid_from: string|null, valid_until: string|null, is_active: bool}  $values
     */
    private function authorizeSave(PricingTier $tier, array $values, User $actor): void
    {
        if (! $tier->exists) {
            $this->authorize($actor, CrmPermission::PricingTierManage, InventoryPermission::PricingManage);

            return;
        }

        $changedKeys = [];

        foreach ($values as $key => $value) {
            if ($this->comparableValue($tier->getAttribute($key)) !== $this->comparableValue($value)) {
                $changedKeys[] = $key;
            }
        }

        $discountOnly = array_diff($changedKeys, ['discount_type', 'discount_value']) === [];

        $this->authorize(
            $actor,
            $discountOnly ? CrmPermission::PricingTierDiscountManage : CrmPermission::PricingTierManage,
            InventoryPermission::PricingManage,
        );
    }

    private function authorize(User $actor, CrmPermission $crmPermission, InventoryPermission $inventoryPermission): void
    {
        if ($actor->isAdmin() && ! $actor->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return;
        }

        if (! $actor->can($crmPermission->value) && ! $actor->can($inventoryPermission->value)) {
            throw new DomainException('You are not authorized to perform this pricing-tier action.');
        }
    }

    private function assertUniqueName(string $name, PricingTier $tier): void
    {
        $exists = PricingTier::query()->withTrashed()
            ->where('name', $name)
            ->when($tier->exists, fn (Builder $query): Builder => $query->whereKeyNot($tier->getKey()))
            ->exists();

        if ($exists) {
            throw new DomainException('A pricing tier with this name already exists.');
        }
    }

    private function deactivateOtherSpecificTiers(int $customerId, PricingTier $currentTier, User $actor): void
    {
        $tiers = PricingTier::query()
            ->where('tier_type', PricingTierType::CustomerSpecific)
            ->where('customer_user_id', $customerId)
            ->where('is_active', true)
            ->when($currentTier->exists, fn (Builder $query): Builder => $query->whereKeyNot($currentTier->getKey()))
            ->lockForUpdate()
            ->get();

        foreach ($tiers as $tier) {
            $oldValues = $this->tierValues($tier);
            $tier->forceFill(['is_active' => false, 'updated_by' => $actor->getKey()])->save();
            $this->auditStateChange('pricing.tier.deactivated', $tier, $oldValues, $actor);
        }
    }

    /** @param list<int> $ids */
    private function assertUniqueIds(array $ids, string $label): void
    {
        if (count($ids) !== count(array_unique($ids))) {
            throw new DomainException('Duplicate '.$label.' are not allowed.');
        }
    }

    private function lockTier(PricingTier $tier): PricingTier
    {
        $id = $tier->getKey();

        if (! is_int($id)) {
            throw new DomainException('A persisted pricing tier is required.');
        }

        return PricingTier::query()->withTrashed()->lockForUpdate()->findOrFail($id);
    }

    private function comparableValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }

    /**
     * @return array{name: string, tier_type: string, discount_type: string, discount_value: float, customer_user_id: int|null, visibility: string|null, valid_from: string|null, valid_until: string|null, is_active: bool}
     */
    private function tierValues(PricingTier $tier): array
    {
        return [
            'name' => $tier->name,
            'tier_type' => $tier->tier_type->value,
            'discount_type' => $tier->discount_type->value,
            'discount_value' => (float) $tier->discount_value,
            'customer_user_id' => $tier->customer_user_id,
            'visibility' => $tier->visibility?->value,
            'valid_from' => $tier->valid_from?->toDateString(),
            'valid_until' => $tier->valid_until?->toDateString(),
            'is_active' => $tier->is_active,
        ];
    }

    /** @param array<string, mixed> $oldValues */
    private function auditStateChange(string $action, PricingTier $tier, array $oldValues, User $actor): void
    {
        activity()
            ->performedOn($tier)
            ->causedBy($actor)
            ->withChanges([
                'old' => $oldValues,
                'attributes' => $this->tierValues($tier),
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log($action);
    }

    /** @param list<int> $oldIds
     * @param  list<int>  $newIds
     */
    private function auditRelationshipChange(string $action, PricingTier $tier, string $key, array $oldIds, array $newIds, User $actor): void
    {
        if ($oldIds === $newIds) {
            return;
        }

        activity()
            ->performedOn($tier)
            ->causedBy($actor)
            ->withChanges([
                'old' => [$key => $oldIds],
                'attributes' => [$key => $newIds],
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log($action);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['19', '23000', '23505'], true);
    }
}
