<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SalesPermission;
use App\Models\CreditNote;
use App\Models\User;
use App\Policies\Concerns\ChecksSalesPermissions;

final class CreditNotePolicy
{
    use ChecksSalesPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'viewAny');
    }

    public function view(User $user, CreditNote $creditNote): bool
    {
        return $this->authorizeSalesAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'create');
    }

    public function update(User $user, CreditNote $creditNote): bool
    {
        return ! $creditNote->isConfirmed()
            && $this->authorizeSalesAbility($user, 'update');
    }

    public function delete(User $user, CreditNote $creditNote): bool
    {
        return ! $creditNote->isConfirmed()
            && $this->authorizeSalesAbility($user, 'delete');
    }

    /** @return array<string, string> */
    protected function salesPermissionMap(): array
    {
        return [
            'viewAny' => SalesPermission::CreditNoteView->value,
            'view' => SalesPermission::CreditNoteView->value,
            'create' => SalesPermission::CreditNoteManage->value,
            'update' => SalesPermission::CreditNoteManage->value,
            'delete' => SalesPermission::CreditNoteManage->value,
        ];
    }
}
