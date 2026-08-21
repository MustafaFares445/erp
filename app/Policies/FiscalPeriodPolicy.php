<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountingPermissions;

/**
 * Fiscal period authorization.
 *
 * `close` is a distinct ability from `update` (FR-040): creating next year's
 * periods is routine, while declaring a period final — after which nothing can
 * be posted into or corrected inside it — is not. `reopen` shares the same
 * permission, since anyone trusted to close a period is trusted to undo it.
 */
final class FiscalPeriodPolicy
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

    public function close(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'close');
    }

    public function reopen(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'reopen');
    }

    /** @return array<string, string> */
    protected function accountingPermissionMap(): array
    {
        return [
            'viewAny' => AccountingPermission::FiscalPeriodView->value,
            'view' => AccountingPermission::FiscalPeriodView->value,
            'create' => AccountingPermission::FiscalPeriodManage->value,
            'update' => AccountingPermission::FiscalPeriodManage->value,
            'delete' => AccountingPermission::FiscalPeriodManage->value,
            'close' => AccountingPermission::FiscalPeriodClose->value,
            'reopen' => AccountingPermission::FiscalPeriodClose->value,
        ];
    }
}
