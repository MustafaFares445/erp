<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Models\Refund;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountingPermissions;

final class RefundPolicy
{
    use ChecksAccountingPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'create');
    }

    public function update(User $user, Refund $refund): bool
    {
        return $refund->isDraft() && $this->authorizeAccountingAbility($user, 'update');
    }

    public function delete(User $user, Refund $refund): bool
    {
        return $refund->isDraft() && $this->authorizeAccountingAbility($user, 'delete');
    }

    public function approve(User $user, Refund $refund): bool
    {
        return $refund->isDraft()
            && $refund->created_by !== $user->getKey()
            && $this->authorizeAccountingAbility($user, 'approve');
    }

    public function pay(User $user, Refund $refund): bool
    {
        return $refund->isApproved() && $this->authorizeAccountingAbility($user, 'pay');
    }

    /** @return array<string, string> */
    protected function accountingPermissionMap(): array
    {
        return [
            'viewAny' => AccountingPermission::RefundView->value,
            'view' => AccountingPermission::RefundView->value,
            'create' => AccountingPermission::RefundManage->value,
            'update' => AccountingPermission::RefundManage->value,
            'delete' => AccountingPermission::RefundManage->value,
            'approve' => AccountingPermission::RefundApprove->value,
            'pay' => AccountingPermission::RefundPay->value,
        ];
    }
}
