<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\DashboardRole;
use App\Models\User;

/**
 * Shared authorization shape for every purchasing policy
 * (contracts/permissions.md §4).
 *
 * Mirrors {@see ChecksSupportPermissions} exactly, including the admin-bypass
 * semantics: an admin holding **no** fixed dashboard role keeps full bypass,
 * while an admin who has been given one is governed by that role's grants.
 * Adding the two purchasing roles to {@see DashboardRole} therefore narrows
 * bypass in every other module too, which is the documented intent rather than
 * a side effect.
 */
trait ChecksPurchasePermissions
{
    /** @return array<string, string> */
    abstract protected function purchasePermissionMap(): array;

    protected function authorizePurchaseAbility(User $user, string $ability): bool
    {
        $permission = $this->purchasePermissionMap()[$ability] ?? null;

        if ($permission === null) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can($permission);
    }

    /**
     * No permission permits a hard delete of a purchasing record (FR-009, R-F).
     *
     * A purchase order is a financial commitment; the archive is the audit
     * trail, so removing the row removes the evidence.
     */
    public function forceDelete(): bool
    {
        return false;
    }
}
