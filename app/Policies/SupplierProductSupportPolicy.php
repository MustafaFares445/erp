<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PurchasePermission;
use App\Models\User;
use App\Policies\Concerns\ChecksPurchasePermissions;

/**
 * Supplier capability is a Purchasing-owned commercial master-data fact.
 *
 * It intentionally reuses the supplier-product-reference permission boundary:
 * users who may maintain the supplier catalogue may maintain capability, while
 * Purchasing/Reviewer read roles retain read-only visibility.
 */
final class SupplierProductSupportPolicy
{
    use ChecksPurchasePermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'view');
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
