<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountingPermissions;

/**
 * Chart of accounts authorization.
 *
 * `viewLedger` is kept separate from `view` so a data-entry role can be given
 * the accounts it must post to without company-wide balances
 * (permissions.md R-4).
 */
final class ChartAccountPolicy
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

    public function update(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'update');
    }

    public function delete(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'delete');
    }

    public function restore(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'restore');
    }

    public function viewLedger(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'viewLedger');
    }

    /** @return array<string, string> */
    protected function accountingPermissionMap(): array
    {
        return [
            'viewAny' => AccountingPermission::ChartAccountView->value,
            'view' => AccountingPermission::ChartAccountView->value,
            'create' => AccountingPermission::ChartAccountManage->value,
            'update' => AccountingPermission::ChartAccountManage->value,
            'delete' => AccountingPermission::ChartAccountManage->value,
            'restore' => AccountingPermission::ChartAccountManage->value,
            'viewLedger' => AccountingPermission::LedgerView->value,
        ];
    }
}
