<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\CrmPermission;
use App\Enums\ProductStatus;
use App\Enums\ProductSubscriptionDiscountType;
use App\Enums\ProductSubscriptionVisibility;
use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\ProductSubscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ProductSubscriptionService
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @param  array<mixed>  $attributes
     * @param  list<int>  $productIds
     * @param  list<int>  $customerProfileIds
     */
    public function create(array $attributes, array $productIds, array $customerProfileIds, User $actor): ProductSubscription
    {
        $this->authorize($actor, CrmPermission::SubscriptionManage);
        $terms = $this->validatedTerms($attributes);

        try {
            return DB::transaction(function () use ($terms, $productIds, $customerProfileIds, $actor): ProductSubscription {
                $subscription = new ProductSubscription;
                $subscription->forceFill([
                    ...$terms,
                    'is_active' => false,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ])->save();

                $this->replaceProducts($subscription, $productIds, $actor);
                $this->replaceCustomers($subscription, $customerProfileIds, $actor);

                return $subscription->load(['products', 'customerProfiles']);
            }, attempts: 5);
        } catch (QueryException $exception) {
            throw new DomainException('A subscription with this name already exists.', previous: $exception);
        }
    }

    /**
     * @param  array<mixed>  $attributes
     */
    public function update(ProductSubscription $subscription, array $attributes, User $actor): ProductSubscription
    {
        $terms = $this->validatedTerms($attributes);

        return DB::transaction(function () use ($subscription, $terms, $actor): ProductSubscription {
            $lockedSubscription = $this->lockSubscription($subscription);

            $this->authorizeTermChanges($lockedSubscription, $terms, $actor);
            $this->assertUniqueName($terms['name'], $lockedSubscription);

            $lockedSubscription->forceFill([
                ...$terms,
                'updated_by' => $actor->getKey(),
            ])->save();

            return $lockedSubscription->refresh();
        }, attempts: 5);
    }

    /** @param list<int> $customerProfileIds */
    public function assignCustomers(ProductSubscription $subscription, array $customerProfileIds, User $actor): ProductSubscription
    {
        $this->authorize($actor, CrmPermission::SubscriptionLinkManage);

        return DB::transaction(function () use ($subscription, $customerProfileIds, $actor): ProductSubscription {
            $lockedSubscription = $this->lockSubscription($subscription);
            $this->addCustomers($lockedSubscription, $customerProfileIds, $actor);

            return $lockedSubscription->load('customerProfiles');
        }, attempts: 5);
    }

    /** @param list<int> $productIds */
    public function assignProducts(ProductSubscription $subscription, array $productIds, User $actor): ProductSubscription
    {
        $this->authorize($actor, CrmPermission::SubscriptionLinkManage);

        return DB::transaction(function () use ($subscription, $productIds, $actor): ProductSubscription {
            $lockedSubscription = $this->lockSubscription($subscription);
            $this->addProducts($lockedSubscription, $productIds, $actor);

            return $lockedSubscription->load('products');
        }, attempts: 5);
    }

    /** @param list<int> $productIds */
    public function unassignProducts(ProductSubscription $subscription, array $productIds, User $actor): ProductSubscription
    {
        $this->authorize($actor, CrmPermission::SubscriptionLinkManage);

        return DB::transaction(function () use ($subscription, $productIds, $actor): ProductSubscription {
            $lockedSubscription = $this->lockSubscription($subscription);
            $this->detachProducts($lockedSubscription, $productIds, $actor);

            return $lockedSubscription->load('products');
        }, attempts: 5);
    }

    /** @param list<int> $customerProfileIds */
    public function unassignCustomers(ProductSubscription $subscription, array $customerProfileIds, User $actor): ProductSubscription
    {
        $this->authorize($actor, CrmPermission::SubscriptionLinkManage);

        return DB::transaction(function () use ($subscription, $customerProfileIds, $actor): ProductSubscription {
            $lockedSubscription = $this->lockSubscription($subscription);
            $this->detachCustomers($lockedSubscription, $customerProfileIds, $actor);

            return $lockedSubscription->load('customerProfiles');
        }, attempts: 5);
    }

    public function activate(ProductSubscription $subscription, User $actor): ProductSubscription
    {
        $this->authorize($actor, CrmPermission::SubscriptionManage);

        return DB::transaction(function () use ($subscription, $actor): ProductSubscription {
            $lockedSubscription = $this->lockSubscription($subscription);

            if (! $this->hasActiveProduct($lockedSubscription)) {
                throw new DomainException('A subscription requires at least one active product before activation.');
            }

            if ($lockedSubscription->visibility === ProductSubscriptionVisibility::Restricted && ! $this->hasActiveCustomerAssignment($lockedSubscription)) {
                throw new DomainException('A restricted subscription requires an active customer assignment before activation.');
            }

            $lockedSubscription->forceFill(['is_active' => true, 'updated_by' => $actor->getKey()])->save();

            return $lockedSubscription->refresh();
        }, attempts: 5);
    }

    public function deactivate(ProductSubscription $subscription, User $actor): ProductSubscription
    {
        $this->authorize($actor, CrmPermission::SubscriptionManage);

        return DB::transaction(function () use ($subscription, $actor): ProductSubscription {
            $lockedSubscription = $this->lockSubscription($subscription);
            $lockedSubscription->forceFill(['is_active' => false, 'updated_by' => $actor->getKey()])->save();

            return $lockedSubscription->refresh();
        }, attempts: 5);
    }

    public function delete(ProductSubscription $subscription, User $actor): void
    {
        $this->authorize($actor, CrmPermission::SubscriptionManage);

        DB::transaction(function () use ($subscription, $actor): void {
            $lockedSubscription = $this->lockSubscription($subscription);
            $lockedSubscription->forceFill(['is_active' => false, 'updated_by' => $actor->getKey()])->save();
            $lockedSubscription->delete();
        }, attempts: 5);
    }

    public function restore(ProductSubscription $subscription, User $actor): ProductSubscription
    {
        $this->authorize($actor, CrmPermission::SubscriptionRestore);

        return DB::transaction(function () use ($subscription, $actor): ProductSubscription {
            $lockedSubscription = ProductSubscription::withTrashed()
                ->lockForUpdate()
                ->findOrFail($this->subscriptionId($subscription));
            $lockedSubscription->forceFill(['is_active' => false, 'updated_by' => $actor->getKey()])->save();
            $lockedSubscription->restore();

            return $lockedSubscription->refresh();
        }, attempts: 5);
    }

    /** @param list<int> $productIds */
    private function replaceProducts(ProductSubscription $subscription, array $productIds, User $actor): void
    {
        $this->assertUniqueIds($productIds, 'product links');
        $subscription->products()->sync($this->activeProductIds($productIds));
        $subscription->forceFill(['updated_by' => $actor->getKey()])->saveQuietly();
    }

    /** @param list<int> $productIds */
    private function addProducts(ProductSubscription $subscription, array $productIds, User $actor): void
    {
        $this->assertUniqueIds($productIds, 'product links');
        $activeProductIds = $this->activeProductIds($productIds);

        if ($activeProductIds === []) {
            return;
        }

        $existingProductIds = $subscription->products()
            ->get()
            ->map(static fn (Product $product): int => $product->id)
            ->values()
            ->all();

        if (array_intersect($existingProductIds, $activeProductIds) !== []) {
            throw new DomainException('A product can only be linked to a subscription once.');
        }

        $subscription->products()->attach($activeProductIds);
        $subscription->forceFill(['updated_by' => $actor->getKey()])->saveQuietly();
        $this->auditRelationshipChange('subscription.products.attached', $subscription, 'products', $activeProductIds, $actor);
    }

    /** @param list<int> $productIds */
    private function detachProducts(ProductSubscription $subscription, array $productIds, User $actor): void
    {
        $this->assertUniqueIds($productIds, 'product links');
        $this->assertAttached($subscription->products()->whereKey($productIds)->count(), $productIds, 'product links');

        $subscription->products()->detach($productIds);
        $subscription->forceFill(['updated_by' => $actor->getKey()])->saveQuietly();
        $this->auditRelationshipChange('subscription.products.detached', $subscription, 'products', $productIds, $actor);
    }

    /** @param list<int> $productIds
     * @return list<int>
     */
    private function activeProductIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $activeProductIds = Product::query()
            ->whereKey($productIds)
            ->where('is_active', true)
            ->where('status', ProductStatus::Active->value)
            ->get()
            ->map(static fn (Product $product): int => $product->id)
            ->values()
            ->all();

        if (count($activeProductIds) !== count($productIds)) {
            throw new DomainException('Subscriptions can only link active products.');
        }

        return array_values($activeProductIds);
    }

    /** @param list<int> $customerProfileIds */
    private function replaceCustomers(ProductSubscription $subscription, array $customerProfileIds, User $actor): void
    {
        $this->assertUniqueIds($customerProfileIds, 'customer assignments');
        $subscription->customerProfiles()->sync($this->activeCustomerIds($customerProfileIds));
        $subscription->forceFill(['updated_by' => $actor->getKey()])->saveQuietly();
    }

    /** @param list<int> $customerProfileIds */
    private function addCustomers(ProductSubscription $subscription, array $customerProfileIds, User $actor): void
    {
        $this->assertUniqueIds($customerProfileIds, 'customer assignments');
        $activeCustomerIds = $this->activeCustomerIds($customerProfileIds);

        if ($activeCustomerIds === []) {
            return;
        }

        $existingCustomerIds = $subscription->customerProfiles()
            ->get()
            ->map(static fn (CustomerProfile $customerProfile): int => $customerProfile->id)
            ->values()
            ->all();

        if (array_intersect($existingCustomerIds, $activeCustomerIds) !== []) {
            throw new DomainException('A customer can only be assigned to a subscription once.');
        }

        $subscription->customerProfiles()->attach($activeCustomerIds);
        $subscription->forceFill(['updated_by' => $actor->getKey()])->saveQuietly();
        $this->auditRelationshipChange('subscription.customers.assigned', $subscription, 'customer_profile_ids', $activeCustomerIds, $actor);
    }

    /** @param list<int> $customerProfileIds */
    private function detachCustomers(ProductSubscription $subscription, array $customerProfileIds, User $actor): void
    {
        $this->assertUniqueIds($customerProfileIds, 'customer assignments');
        $this->assertAttached($subscription->customerProfiles()->whereKey($customerProfileIds)->count(), $customerProfileIds, 'customer assignments');

        $subscription->customerProfiles()->detach($customerProfileIds);
        $subscription->forceFill(['updated_by' => $actor->getKey()])->saveQuietly();
        $this->auditRelationshipChange('subscription.customers.unassigned', $subscription, 'customer_profile_ids', $customerProfileIds, $actor);
    }

    /** @param list<int> $customerProfileIds
     * @return list<int>
     */
    private function activeCustomerIds(array $customerProfileIds): array
    {
        if ($customerProfileIds === []) {
            return [];
        }

        $activeCustomerIds = CustomerProfile::query()
            ->whereKey($customerProfileIds)
            ->where('is_active', true)
            ->whereHas('user', fn ($query) => $query->where('user_type', UserType::Customer))
            ->get()
            ->map(static fn (CustomerProfile $customerProfile): int => $customerProfile->id)
            ->values()
            ->all();

        if (count($activeCustomerIds) !== count($customerProfileIds)) {
            throw new DomainException('Subscriptions can only be assigned to active customer profiles.');
        }

        return array_values($activeCustomerIds);
    }

    /**
     * @param  array<mixed>  $attributes
     * @return array{
     *     name: string,
     *     discount_type: ProductSubscriptionDiscountType,
     *     discount_value: float,
     *     visibility: ProductSubscriptionVisibility,
     *     valid_from: DateTimeInterface|null,
     *     valid_until: DateTimeInterface|null
     * }
     */
    private function validatedTerms(array $attributes): array
    {
        $name = $attributes['name'] ?? null;
        $discountType = $attributes['discount_type'] ?? null;
        $discountValue = $attributes['discount_value'] ?? null;
        $visibility = $attributes['visibility'] ?? null;

        if (! is_string($name) || (! $discountType instanceof ProductSubscriptionDiscountType && ! is_string($discountType)) || (! is_int($discountValue) && ! is_float($discountValue) && ! is_string($discountValue)) || (! $visibility instanceof ProductSubscriptionVisibility && ! is_string($visibility))) {
            throw new DomainException('Invalid subscription terms.');
        }

        if (! is_numeric($discountValue)) {
            throw new DomainException('A valid subscription discount is required.');
        }

        $name = mb_trim($name);
        $discountType = $discountType instanceof ProductSubscriptionDiscountType
            ? $discountType
            : ProductSubscriptionDiscountType::from($discountType);
        $discountValue = (float) $discountValue;
        $visibility = $visibility instanceof ProductSubscriptionVisibility
            ? $visibility
            : ProductSubscriptionVisibility::from($visibility);
        $validFromValue = $attributes['valid_from'] ?? null;
        $validUntilValue = $attributes['valid_until'] ?? null;

        if ((! $validFromValue instanceof DateTimeInterface && ! is_string($validFromValue) && $validFromValue !== null) || (! $validUntilValue instanceof DateTimeInterface && ! is_string($validUntilValue) && $validUntilValue !== null)) {
            throw new DomainException('Invalid subscription validity dates.');
        }

        $validFrom = $this->dateOrNull($validFromValue);
        $validUntil = $this->dateOrNull($validUntilValue);

        if ($name === '') {
            throw new DomainException('A subscription name is required.');
        }

        if ($discountValue <= 0) {
            throw new DomainException('A valid subscription discount is required.');
        }

        if ($discountType === ProductSubscriptionDiscountType::Percentage && $discountValue > 100) {
            throw new DomainException('A percentage discount must be between zero and 100.');
        }

        if ($validFrom instanceof DateTimeInterface && $validUntil instanceof DateTimeInterface && $validUntil < $validFrom) {
            throw new DomainException('A subscription validity end date cannot precede its start date.');
        }

        return [
            'name' => $name,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'visibility' => $visibility,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
        ];
    }

    private function dateOrNull(DateTimeInterface|string|null $value): ?DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface ? $value : new DateTimeImmutable($value);
    }

    /**
     * @param array{
     *     name: string,
     *     discount_type: ProductSubscriptionDiscountType,
     *     discount_value: float,
     *     visibility: ProductSubscriptionVisibility,
     *     valid_from: DateTimeInterface|null,
     *     valid_until: DateTimeInterface|null
     * } $terms
     */
    private function authorizeTermChanges(ProductSubscription $subscription, array $terms, User $actor): void
    {
        $discountChanged = $subscription->discount_type !== $terms['discount_type']
            || (float) $subscription->discount_value !== $terms['discount_value'];
        $managementChanged = $subscription->name !== $terms['name']
            || $subscription->visibility !== $terms['visibility']
            || $subscription->valid_from?->toDateString() !== $terms['valid_from']?->format('Y-m-d')
            || $subscription->valid_until?->toDateString() !== $terms['valid_until']?->format('Y-m-d');

        if ($discountChanged) {
            $this->authorize($actor, CrmPermission::SubscriptionDiscountManage);
        }

        if ($managementChanged) {
            $this->authorize($actor, CrmPermission::SubscriptionManage);
        }
    }

    private function assertUniqueName(string $name, ProductSubscription $subscription): void
    {
        if (ProductSubscription::query()->where('name', $name)->whereKeyNot($this->subscriptionId($subscription))->exists()) {
            throw new DomainException('A subscription with this name already exists.');
        }
    }

    /** @param list<int> $ids */
    private function assertUniqueIds(array $ids, string $label): void
    {
        if (count($ids) !== count(array_unique($ids))) {
            throw new DomainException("Duplicate {$label} are not allowed.");
        }
    }

    /** @param list<int> $ids */
    private function assertAttached(int $attachedCount, array $ids, string $label): void
    {
        if ($attachedCount !== count($ids)) {
            throw new DomainException("One or more {$label} are not assigned to this subscription.");
        }
    }

    /** @param list<int> $ids */
    private function auditRelationshipChange(string $action, ProductSubscription $subscription, string $field, array $ids, User $actor): void
    {
        $this->auditLogger->log(
            action: $action,
            entity: $subscription,
            newValues: [$field => $ids],
            actor: $actor,
        );
    }

    private function hasActiveProduct(ProductSubscription $subscription): bool
    {
        return $subscription->products()
            ->where('is_active', true)
            ->where('status', ProductStatus::Active->value)
            ->exists();
    }

    private function hasActiveCustomerAssignment(ProductSubscription $subscription): bool
    {
        return $subscription->customerProfiles()
            ->where('is_active', true)
            ->whereHas('user', fn ($query) => $query->where('user_type', UserType::Customer))
            ->exists();
    }

    private function lockSubscription(ProductSubscription $subscription): ProductSubscription
    {
        return ProductSubscription::query()->lockForUpdate()->findOrFail($this->subscriptionId($subscription));
    }

    private function authorize(User $actor, CrmPermission $permission): void
    {
        if (! $actor->hasAnyRole(CrmPermission::fixedRoleNames()) && $actor->isAdmin()) {
            return;
        }

        if ($actor->can($permission->value)) {
            return;
        }

        throw new DomainException('You are not authorized to manage product subscriptions.');
    }

    private function subscriptionId(ProductSubscription $subscription): int
    {
        $subscriptionId = $subscription->getKey();

        if (! is_int($subscriptionId)) {
            throw new DomainException('A persisted product subscription is required.');
        }

        return $subscriptionId;
    }
}
