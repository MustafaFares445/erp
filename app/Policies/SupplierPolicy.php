<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OperationType;
use App\Enums\PurchasePermission;
use App\Models\Supplier;
use App\Models\User;
use App\Policies\Concerns\ChecksPurchasePermissions;
use App\Policies\Concerns\ReadsPreloadedRelationState;

/**
 * Supplier administration is owned by Purchasing. Logistics consumes safe
 * supplier identity only through product references and canonical receipts.
 */
final class SupplierPolicy
{
    use ChecksPurchasePermissions;
    use ReadsPreloadedRelationState;

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
        if ($this->hasRelated($supplier, 'productReferences')) {
            return true;
        }

        if ($this->hasRelated($supplier, 'productSupports')) {
            return true;
        }

        if ($this->hasReceiptOperations($supplier)) {
            return true;
        }

        if ($this->hasRelated($supplier, 'purchaseOrders')) {
            return true;
        }

        if ($this->hasRelated($supplier, 'confirmations')) {
            return true;
        }

        if ($this->hasRelated($supplier, 'bills')) {
            return true;
        }

        return $this->hasRelated($supplier, 'supplierPayments');
    }

    /** Only receipt operations pin a supplier, so the preloaded flag is a constrained `withExists()`. */
    private function hasReceiptOperations(Supplier $supplier): bool
    {
        if (array_key_exists(Supplier::RECEIPT_OPERATIONS_EXISTS, $supplier->getAttributes())) {
            return (bool) $supplier->getAttribute(Supplier::RECEIPT_OPERATIONS_EXISTS);
        }

        return $supplier->inventoryOperations()
            ->where('operation_type', OperationType::Receipt)
            ->exists();
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
