<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OperationType;
use App\Enums\PurchasePermission;
use App\Models\Supplier;
use App\Models\User;
use App\Policies\Concerns\ChecksPurchasePermissions;

/**
 * Supplier administration is owned by Purchasing. Logistics consumes safe
 * supplier identity only through product references and canonical receipts.
 */
final class SupplierPolicy
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

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->authorizePurchaseAbility($user, 'delete')
            && ! $this->isReferenced($supplier);
    }

    public function restore(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'restore');
    }

    private function isReferenced(Supplier $supplier): bool
    {
        if ($supplier->productReferences()->exists()) {
            return true;
        }

        if ($supplier->inventoryOperations()
            ->where('operation_type', OperationType::Receipt)
            ->exists()) {
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
