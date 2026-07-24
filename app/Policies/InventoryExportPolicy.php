<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

final class InventoryExportPolicy
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

    /** @return array<string, string> */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::Export->value,
            'view' => InventoryPermission::Export->value,
            'create' => InventoryPermission::Export->value,
        ];
    }
}
