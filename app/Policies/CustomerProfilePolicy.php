<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CrmPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;

final class CustomerProfilePolicy
{
    use ChecksCrmPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'create');
    }

    public function update(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'update');
    }

    public function delete(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'deleteAny');
    }

    public function restore(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'restoreAny');
    }

    public function forceDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function crmPermissionMap(): array
    {
        return [
            'viewAny' => CrmPermission::CustomerView->value,
            'view' => CrmPermission::CustomerView->value,
            'create' => CrmPermission::CustomerManage->value,
            'update' => CrmPermission::CustomerManage->value,
            'delete' => CrmPermission::CustomerManage->value,
            'deleteAny' => CrmPermission::CustomerManage->value,
            'restore' => CrmPermission::CustomerRestore->value,
            'restoreAny' => CrmPermission::CustomerRestore->value,
        ];
    }
}
