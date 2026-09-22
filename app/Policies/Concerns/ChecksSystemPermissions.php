<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\DashboardRole;
use App\Models\User;

/**
 * Shared authorization shape for system-wide settings policies.
 *
 * Mirrors {@see ChecksPurchasePermissions} exactly, including the admin-bypass
 * semantics: an admin holding no fixed dashboard role keeps full bypass, while
 * an admin who has been given one is governed by that role's grants.
 */
trait ChecksSystemPermissions
{
    /** @return array<string, string> */
    abstract protected function systemPermissionMap(): array;

    protected function authorizeSystemAbility(User $user, string $ability): bool
    {
        $permission = $this->systemPermissionMap()[$ability] ?? null;

        if ($permission === null) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can($permission);
    }

    /**
     * Nothing hard-deletes a settings record.
     *
     * The stored row is the evidence of a decision about how the business
     * polices itself; removing it would erase the decision rather than reverse
     * it. Resetting a constraint to its default is a separate, audited act.
     */
    public function forceDelete(): bool
    {
        return false;
    }
}
