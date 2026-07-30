<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\CrmPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait ChecksCrmPermissions
{
    /** @return array<string, string> */
    abstract protected function crmPermissionMap(): array;

    protected function authorizeCrmAbility(User $user, string $ability): bool
    {
        $permission = $this->crmPermissionMap()[$ability] ?? null;

        if ($permission === null) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(CrmPermission::fixedRoleNames())) {
            return true;
        }

        return $user->can($permission);
    }

    public function deleteAny(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'deleteAny');
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return $this->authorizeCrmAbility($user, 'forceDelete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'forceDeleteAny');
    }

    public function restoreAny(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'restoreAny');
    }
}
