<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\Concerns\ChecksInventoryPermissions;
use App\Policies\Concerns\ReadsPreloadedRelationState;

/**
 * Authorizes {@see Warehouse} master-data management (FI-1).
 *
 * Delete is additionally guarded: a warehouse still referenced by any stock
 * or movement row cannot be deleted (FR-005) — the dashboard offers
 * deactivation instead. Hard delete is never permitted (FR-006).
 *
 * @see /Docs/product/ROLES_AND_PERMISSIONS.md
 */
final class WarehousePolicy
{
    use ChecksInventoryPermissions;
    use ReadsPreloadedRelationState;

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

    public function delete(User $user, Warehouse $warehouse): bool
    {
        if (! $this->authorizeInventoryAbility($user, 'delete')) {
            return false;
        }

        return ! $this->isReferenced($warehouse);
    }

    public function restore(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'restore');
    }

    public function forceDelete(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::WarehouseView->value,
            'view' => InventoryPermission::WarehouseView->value,
            'create' => InventoryPermission::WarehouseManage->value,
            'update' => InventoryPermission::WarehouseManage->value,
            'delete' => InventoryPermission::WarehouseManage->value,
            'restore' => InventoryPermission::WarehouseManage->value,
        ];
    }

    private function isReferenced(Warehouse $warehouse): bool
    {
        if ($this->hasRelated($warehouse, 'stocks')) {
            return true;
        }

        return $this->hasRelated($warehouse, 'movements');
    }
}
