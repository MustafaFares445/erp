<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountingPermissions;

final class SupplierPaymentPolicy
{
    use ChecksAccountingPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canManagePayments($user);
    }

    public function view(User $user): bool
    {
        return $this->canManagePayments($user);
    }

    public function create(User $user): bool
    {
        return $this->canManagePayments($user);
    }

    public function update(User $user, SupplierPayment $payment): bool
    {
        return $payment->isDraft() && $this->canManagePayments($user);
    }

    public function delete(User $user, SupplierPayment $payment): bool
    {
        return $payment->isDraft() && $this->canManagePayments($user);
    }

    public function pay(User $user, SupplierPayment $payment): bool
    {
        return $payment->isDraft() && $this->canManagePayments($user);
    }

    /** @return array<string, string> */
    protected function accountingPermissionMap(): array
    {
        return [
            'viewAny' => AccountingPermission::SupplierPaymentManage->value,
            'view' => AccountingPermission::SupplierPaymentManage->value,
            'create' => AccountingPermission::SupplierPaymentManage->value,
            'update' => AccountingPermission::SupplierPaymentManage->value,
            'delete' => AccountingPermission::SupplierPaymentManage->value,
            'pay' => AccountingPermission::SupplierPaymentManage->value,
        ];
    }

    private function canManagePayments(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'view');
    }
}
