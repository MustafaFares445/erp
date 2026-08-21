<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

/**
 * Authorizes the read-only, immutable {@see InventoryMovement} ledger
 * (FI-2).
 *
 * Every write ability is unmapped and therefore denied by default via
 * {@see ChecksInventoryPermissions}, realizing "no create/edit/delete
 * anywhere" (FR-015).
 *
 * @see /specs/002-warehouses-stock-visibility/contracts/authorization.md
 */
final class InventoryMovementPolicy
{
    use ChecksInventoryPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'create');
    }

    public function update(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'update');
    }

    public function delete(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'delete');
    }

    /**
     * @return array<string, string>
     */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::MovementView->value,
            'view' => InventoryPermission::MovementView->value,
        ];
    }
}
