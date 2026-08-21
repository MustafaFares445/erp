<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Enums\PurchasePermission;
use App\Models\Supplier;
use App\Models\User;
use App\Policies\Concerns\ChecksPurchasePermissions;

/**
 * Supplier authorization, held jointly by Purchasing and Inventory.
 *
 * `Supplier` was governed by {@see CatalogPolicy} under `inventory.catalog.*`
 * before this feature existed, and a supplier is genuinely both parties'
 * record: Inventory receives from one, Purchasing orders from one. So this
 * policy grants on **either** catalogue — a purchasing user reaches suppliers
 * through `purchase.supplier.*`, and every inventory catalogue manager keeps
 * the access they already had. Replacing the catalogue grant instead of adding
 * to it would have been a silent regression on shipped behaviour.
 *
 * The delete guard is carried over from {@see CatalogPolicy} unchanged: a
 * supplier with product references or receipts cannot be removed, and this
 * feature adds purchase orders to that list.
 */
final class SupplierPolicy
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

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->authorizeEither($user, 'delete', InventoryPermission::CatalogManage)
            && ! $this->isReferenced($supplier);
    }

    public function restore(User $user): bool
    {
        return $this->authorizeEither($user, 'restore', InventoryPermission::CatalogManage);
    }

    /**
     * Grants when the actor holds the purchasing permission for this ability or
     * the inventory catalogue permission that governed suppliers before.
     */
    private function authorizeEither(User $user, string $ability, InventoryPermission $catalogFallback): bool
    {
        if ($this->authorizePurchaseAbility($user, $ability)) {
            return true;
        }

        return $user->can($catalogFallback->value);
    }

    private function isReferenced(Supplier $supplier): bool
    {
        if ($supplier->productReferences()->exists()) {
            return true;
        }

        if ($supplier->receipts()->exists()) {
            return true;
        }

        return $supplier->purchaseOrders()->exists();
    }

    /** @return array<string, string> */
    protected function purchasePermissionMap(): array
    {
        return [
            'viewAny' => PurchasePermission::SupplierView->value,
            'view' => PurchasePermission::SupplierView->value,
            'create' => PurchasePermission::SupplierManage->value,
            'update' => PurchasePermission::SupplierManage->value,
            'delete' => PurchasePermission::SupplierManage->value,
            'restore' => PurchasePermission::RecordRestore->value,
        ];
    }
}
