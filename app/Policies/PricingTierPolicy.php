<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

final class PricingTierPolicy
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

    public function restore(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'restore');
    }

    public function forceDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::PricingView->value,
            'view' => InventoryPermission::PricingView->value,
            'create' => InventoryPermission::PricingManage->value,
            'update' => InventoryPermission::PricingManage->value,
            'delete' => InventoryPermission::PricingManage->value,
            'restore' => InventoryPermission::PricingManage->value,
        ];
    }
}
