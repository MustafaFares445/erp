<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\PackageType;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

final class PackageTypePolicy
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

    public function delete(User $user, PackageType $packageType): bool
    {
        return $this->authorizeInventoryAbility($user, 'delete') && ! $packageType->isReferenced();
    }

    public function restore(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'restore');
    }

    public function forceDelete(): bool
    {
        return false;
    }

    public function deleteAny(): bool
    {
        return false;
    }

    public function forceDeleteAny(): bool
    {
        return false;
    }

    public function restoreAny(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::PackageView->value,
            'view' => InventoryPermission::PackageView->value,
            'create' => InventoryPermission::PackageManage->value,
            'update' => InventoryPermission::PackageManage->value,
            'delete' => InventoryPermission::PackageManage->value,
            'restore' => InventoryPermission::PackageManage->value,
        ];
    }
}
