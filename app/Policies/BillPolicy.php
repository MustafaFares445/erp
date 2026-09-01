<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Models\Bill;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountingPermissions;

final class BillPolicy
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

    public function update(User $user, Bill $bill): bool
    {
        return $bill->isDraft() && $this->authorizeAccountingAbility($user, 'update');
    }

    public function delete(User $user, Bill $bill): bool
    {
        return $bill->isDraft() && $this->authorizeAccountingAbility($user, 'delete');
    }

    public function approve(User $user, Bill $bill): bool
    {
        return $bill->isDraft()
            && (int) $bill->created_by !== $user->getKey()
            && $this->authorizeAccountingAbility($user, 'approve');
    }

    /** @return array<string, string> */
    protected function accountingPermissionMap(): array
    {
        return [
            'viewAny' => AccountingPermission::BillView->value,
            'view' => AccountingPermission::BillView->value,
            'create' => AccountingPermission::BillManage->value,
            'update' => AccountingPermission::BillManage->value,
            'delete' => AccountingPermission::BillManage->value,
            'approve' => AccountingPermission::BillApprove->value,
        ];
    }
}
