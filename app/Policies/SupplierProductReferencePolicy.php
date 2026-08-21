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
 * Like {@see SupplierPolicy}, this grants on either the purchasing catalogue or
 * the inventory one. References were reachable through the supplier form under
 * `inventory.catalog.*` before this feature gave them a surface of their own,
 * and taking that away would be a regression rather than a tightening.
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
        return $this->authorizeEither($user, 'create', InventoryPermission::CatalogManage);
    }

    public function update(User $user): bool
    {
        return $this->authorizeEither($user, 'update', InventoryPermission::CatalogManage);
    }

    public function delete(User $user): bool
    {
        return $this->authorizeEither($user, 'delete', InventoryPermission::CatalogManage);
    }

    public function restore(User $user): bool
    {
        return $this->authorizeEither($user, 'restore', InventoryPermission::CatalogManage);
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
