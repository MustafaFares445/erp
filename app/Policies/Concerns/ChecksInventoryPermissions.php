<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\InventoryPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable ability→permission resolution for inventory resource policies.
 *
 * Consuming policies implement {@see self::inventoryPermissionMap()} to
 * declare which `inventory.*` permission (see {@see InventoryPermission})
 * backs each policy ability, then delegate their ability methods to
 * {@see self::authorizeInventoryAbility()}. Authorization is resolved via
 * the same Spatie permission checks used by every other access channel —
 * no forked/dashboard-specific ACL (constitution Principle IV).
 *
 * An ability with no entry in the map is denied by default, which is how
 * this pattern realizes "no delete capability" for read-only ledgers.
 *
 * @see /specs/001-inventory-dashboard-foundation/contracts/policy-abilities.md
 */
trait ChecksInventoryPermissions
{
    /**
     * @return array<string, string> Policy ability => `inventory.*` permission name.
     */
    abstract protected function inventoryPermissionMap(): array;

    protected function authorizeInventoryAbility(User $user, string $ability): bool
    {
        $permission = $this->inventoryPermissionMap()[$ability] ?? null;

        if ($permission === null) {
            return false;
        }

        return $user->can($permission);
    }

    public function deleteAny(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'deleteAny');
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return $this->authorizeInventoryAbility($user, 'forceDelete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'forceDeleteAny');
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->authorizeInventoryAbility($user, 'restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'restoreAny');
    }

    public function replicate(User $user, Model $model): bool
    {
        return $this->authorizeInventoryAbility($user, 'replicate');
    }

    public function reorder(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'reorder');
    }
}
