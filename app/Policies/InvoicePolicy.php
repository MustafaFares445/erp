<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Enums\SalesPermission;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountingPermissions;
use App\Policies\Concerns\ChecksSalesPermissions;

final class InvoicePolicy
{
    use ChecksAccountingPermissions, ChecksSalesPermissions {
        ChecksSalesPermissions::forceDelete insteadof ChecksAccountingPermissions;
    }

    public function viewAny(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'viewAny')
            || $this->authorizeSalesAbility($user, 'viewAny');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->authorizeAccountingAbility($user, 'view')
            || $this->authorizeSalesAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'create');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $invoice->isDraft()
            && $this->authorizeSalesAbility($user, 'update');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $invoice->isDraft()
            && $this->authorizeSalesAbility($user, 'delete');
    }

    /** @return array<string, string> */
    protected function accountingPermissionMap(): array
    {
        return [
            'viewAny' => AccountingPermission::ReceivableView->value,
            'view' => AccountingPermission::ReceivableView->value,
        ];
    }

    /** @return array<string, string> */
    protected function salesPermissionMap(): array
    {
        return [
            'viewAny' => SalesPermission::InvoiceView->value,
            'view' => SalesPermission::InvoiceView->value,
            'create' => SalesPermission::InvoiceManage->value,
            'update' => SalesPermission::InvoiceManage->value,
            'delete' => SalesPermission::InvoiceManage->value,
        ];
    }
}
