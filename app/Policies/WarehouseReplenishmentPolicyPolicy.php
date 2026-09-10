<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\User;
use App\Models\WarehouseReplenishmentPolicy;
use App\Policies\Concerns\ChecksInventoryPermissions;

/**
 * Authorizes {@see WarehouseReplenishmentPolicy} management.
 *
 * Replenishment policy visibility and mutation are deliberately independent
 * from stock visibility and warehouse administration. Phase 1 introduces the
 * dedicated permissions so a user can maintain Min/Max policy without gaining
 * unrelated warehouse configuration powers, and vice versa.
 */
final class WarehouseReplenishmentPolicyPolicy
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

    /** @return array<string, string> */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::ReplenishmentPolicyView->value,
            'view' => InventoryPermission::ReplenishmentPolicyView->value,
            'create' => InventoryPermission::ReplenishmentPolicyManage->value,
            'update' => InventoryPermission::ReplenishmentPolicyManage->value,
            'delete' => InventoryPermission::ReplenishmentPolicyManage->value,
        ];
    }
}
