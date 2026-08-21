<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\DashboardRole;
use App\Models\User;

/**
 * Shared `accounting.*` authorization, mirroring
 * {@see ChecksSupportPermissions} so the admin-bypass rule reads identically
 * across modules.
 *
 * A user flagged `isAdmin()` who holds no fixed dashboard role keeps the blanket
 * bypass; one who holds any fixed role — including another module's — is checked
 * explicitly. Assigning a scoped role is a statement that this user's access is
 * scoped.
 *
 * @see /specs/018-chart-of-accounts-journals/contracts/permissions.md §4
 */
trait ChecksAccountingPermissions
{
    /** @return array<string, string> */
    abstract protected function accountingPermissionMap(): array;

    protected function authorizeAccountingAbility(User $user, string $ability): bool
    {
        $permission = $this->accountingPermissionMap()[$ability] ?? null;

        if ($permission === null) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can($permission);
    }

    /**
     * No role hard-deletes an accounting record (permissions.md R-2). An
     * account carries history that its posted lines still reference, and a
     * posted entry may never be removed at all.
     */
    public function forceDelete(): bool
    {
        return false;
    }
}
