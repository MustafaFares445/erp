<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Enums\InvoiceStatus;
use App\Enums\SalesPermission;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\ChecksSalesPermissions;

final class InvoicePolicy
{
    use ChecksSalesPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'viewAny')
            || $user->can(AccountingPermission::ReceivableView->value);
    }

    public function view(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'view')
            || $user->can(AccountingPermission::ReceivableView->value);
    }

    public function create(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'create');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $invoice->isDraft() && $this->authorizeSalesAbility($user, 'update');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $invoice->isDraft() && $this->authorizeSalesAbility($user, 'delete');
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $invoice->isDraft() && $this->authorizeSalesAbility($user, 'issue');
    }

    public function send(User $user, Invoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Sent], true)
            && $this->authorizeSalesAbility($user, 'send');
    }

    public function confirmReceipt(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Sent
            && $this->authorizeSalesAbility($user, 'confirmReceipt');
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
            'issue' => SalesPermission::InvoiceIssue->value,
            'send' => SalesPermission::InvoiceSend->value,
            'confirmReceipt' => SalesPermission::InvoiceConfirmReceipt->value,
        ];
    }
}
