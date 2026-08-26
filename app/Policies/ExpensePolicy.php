<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Models\Expense;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountingPermissions;

final class ExpensePolicy
{
    use ChecksAccountingPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'viewAny');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->authorizeAccountingAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'create');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $expense->isDraft() && $this->authorizeAccountingAbility($user, 'update');
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $expense->isDraft() && $this->authorizeAccountingAbility($user, 'delete');
    }

    public function approve(User $user, Expense $expense): bool
    {
        return $expense->isDraft()
            && (int) $expense->created_by !== $user->getKey()
            && $this->authorizeAccountingAbility($user, 'approve');
    }

    public function pay(User $user, Expense $expense): bool
    {
        return $expense->status === 'approved'
            && $this->authorizeAccountingAbility($user, 'pay');
    }

    /** @return array<string, string> */
    protected function accountingPermissionMap(): array
    {
        return [
            'viewAny' => AccountingPermission::ExpenseView->value,
            'view' => AccountingPermission::ExpenseView->value,
            'create' => AccountingPermission::ExpenseManage->value,
            'update' => AccountingPermission::ExpenseManage->value,
            'delete' => AccountingPermission::ExpenseManage->value,
            'approve' => AccountingPermission::ExpenseApprove->value,
            'pay' => AccountingPermission::ExpenseManage->value,
        ];
    }
}
