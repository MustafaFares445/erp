<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\User;
use App\Models\WarehouseReplenishmentPolicy;
use App\Policies\Concerns\ChecksInventoryPermissions;

/**
 * Authorizes {@see WarehouseReplenishmentPolicy} management
 * (Phase 0 remediation). Viewing reuses the stock-visibility permission
 * since a policy is read alongside stock levels; writing reuses
 * warehouse-management, since setting a warehouse's replenishment targets is
 * the same kind of warehouse-configuration decision as managing the
 * warehouse itself — no dedicated permission exists for this yet.
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

    /**
     * @return array<string, string>
     */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::StockView->value,
            'view' => InventoryPermission::StockView->value,
            'create' => InventoryPermission::WarehouseManage->value,
            'update' => InventoryPermission::WarehouseManage->value,
            'delete' => InventoryPermission::WarehouseManage->value,
        ];
    }
}
