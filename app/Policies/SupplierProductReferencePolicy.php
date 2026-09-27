<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Enums\PurchasePermission;
use App\Models\User;
use App\Policies\Concerns\ChecksPurchasePermissions;

/**
 * Supplier product reference authorization.
 *
 * Inventory may read supplier references as safe catalogue identity, but only
 * Purchasing may create, update, delete, or restore commercial supplier facts
 * such as supplier item numbers, negotiated costs, and reference currencies.
 */
final class SupplierProductReferencePolicy
{
    use ChecksPurchasePermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeEither($user, 'viewAny', InventoryPermission::CatalogView);
    }

    public function view(User $user): bool
    {
        return $this->authorizeEither($user, 'view', InventoryPermission::CatalogView);
    }

    public function create(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'create');
    }

    public function update(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'update');
    }

    public function delete(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'delete');
    }

    public function restore(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'restore');
    }

    private function authorizeEither(User $user, string $ability, InventoryPermission $catalogFallback): bool
    {
        if ($this->authorizePurchaseAbility($user, $ability)) {
            return true;
        }

        return $user->can($catalogFallback->value);
    }

    /** @return array<string, string> */
    protected function purchasePermissionMap(): array
    {
        return [
            'viewAny' => PurchasePermission::ProductReferenceView->value,
            'view' => PurchasePermission::ProductReferenceView->value,
            'create' => PurchasePermission::ProductReferenceManage->value,
            'update' => PurchasePermission::ProductReferenceManage->value,
            'delete' => PurchasePermission::ProductReferenceManage->value,
            'restore' => PurchasePermission::RecordRestore->value,
        ];
    }
}
