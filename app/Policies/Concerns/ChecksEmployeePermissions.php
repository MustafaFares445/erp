<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\DashboardRole;
use App\Models\User;

trait ChecksEmployeePermissions
{
    /** @return array<string, string> */
    abstract protected function employeePermissionMap(): array;

    protected function authorizeEmployeeAbility(User $user, string $ability): bool
    {
        $permission = $this->employeePermissionMap()[$ability] ?? null;

        if ($permission === null) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can($permission);
    }

    public function forceDelete(): bool
    {
        return false;
    }
}
