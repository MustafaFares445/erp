<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\CrmPermission;
use App\Models\User;

trait ChecksCrmPermissions
{
    /** @return array<string, string> */
    abstract protected function crmPermissionMap(): array;

    protected function authorizeCrmAbility(User $user, string $ability): bool
    {
        $permission = $this->crmPermissionMap()[$ability];

        if ($user->isAdmin() && ! $user->hasAnyRole(CrmPermission::fixedRoleNames())) {
            return true;
        }

        return $user->can($permission);
    }
}
