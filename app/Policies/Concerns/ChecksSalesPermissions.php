<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\DashboardRole;
use App\Models\User;

/**
 * Shared `sales.*` authorization, mirroring {@see ChecksAccountingPermissions}
 * so the admin-bypass rule reads identically across modules.
 *
 * A user flagged `isAdmin()` who holds no fixed dashboard role keeps the blanket
 * bypass; one who holds any fixed role — including another module's — is checked
 * explicitly. Assigning a scoped role is a statement that this user's access is
 * scoped.
 *
 * @see /specs/019-sales-lifecycle-payments-credits/contracts/permissions.md §4
 */
trait ChecksSalesPermissions
{
    /** @return array<string, string> */
    abstract protected function salesPermissionMap(): array;

    protected function authorizeSalesAbility(User $user, string $ability): bool
    {
        $permission = $this->salesPermissionMap()[$ability] ?? null;

        if ($permission === null) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can($permission);
    }

    /**
     * No role hard-deletes a sales record. An issued invoice, a posted
     * payment, and a confirmed credit note are never deleted by any path,
     * soft or hard — correction is reversal or a credit note
     * (constitution Principle III).
     */
    public function forceDelete(): bool
    {
        return false;
    }
}
