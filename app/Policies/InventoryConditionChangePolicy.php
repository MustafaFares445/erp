<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\InventoryConditionChange;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

final class InventoryConditionChangePolicy
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

    public function post(User $user, InventoryConditionChange $change): bool
    {
        return $change->isDraft() && $this->authorizeInventoryAbility($user, 'post');
    }

    public function cancel(User $user, InventoryConditionChange $change): bool
    {
        return $change->isDraft() && $this->authorizeInventoryAbility($user, 'cancel');
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(User $user, InventoryConditionChange $change): bool
    {
        return $change->isDraft() && $this->authorizeInventoryAbility($user, 'cancel');
    }

    public function forceDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::ConditionChangeView->value,
            'view' => InventoryPermission::ConditionChangeView->value,
            'create' => InventoryPermission::ConditionChangeCreate->value,
            'post' => InventoryPermission::ConditionChangePost->value,
            'cancel' => InventoryPermission::ConditionChangeCancel->value,
        ];
    }
}
