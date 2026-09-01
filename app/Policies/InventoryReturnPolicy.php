<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\InventoryReturn;
use App\Models\User;

final class InventoryReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(InventoryPermission::ReturnView->value);
    }

    public function view(User $user): bool
    {
        return $user->can(InventoryPermission::ReturnView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(InventoryPermission::ReturnCreate->value);
    }

    public function update(User $user, InventoryReturn $return): bool
    {
        return $user->can(InventoryPermission::ReturnCreate->value) && $return->isDraft();
    }

    public function inspect(User $user, InventoryReturn $return): bool
    {
        return $user->can(InventoryPermission::ReturnInspect->value)
            && $return->isDraft();
    }

    public function markReady(User $user, InventoryReturn $return): bool
    {
        return $user->can(InventoryPermission::ReturnPost->value) && $return->isDraft();
    }

    public function post(User $user, InventoryReturn $return): bool
    {
        return $user->can(InventoryPermission::ReturnPost->value) && $return->isReady();
    }

    public function cancel(User $user, InventoryReturn $return): bool
    {
        return $user->can(InventoryPermission::ReturnCancel->value)
            && ! $return->isTerminal();
    }

    public function delete(): bool
    {
        return false;
    }

    public function deleteAny(): bool
    {
        return false;
    }

    public function forceDelete(): bool
    {
        return false;
    }

    public function forceDeleteAny(): bool
    {
        return false;
    }

    public function restore(): bool
    {
        return false;
    }

    public function restoreAny(): bool
    {
        return false;
    }
}
