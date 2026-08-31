<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\InventoryCorrection;
use App\Models\User;

final class InventoryCorrectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(InventoryPermission::CorrectionView->value);
    }

    public function view(User $user, InventoryCorrection $correction): bool
    {
        return $user->can(InventoryPermission::CorrectionView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(InventoryPermission::CorrectionCreate->value);
    }

    public function update(User $user, InventoryCorrection $correction): bool
    {
        return $user->can(InventoryPermission::CorrectionCreate->value) && $correction->isDraft();
    }

    public function post(User $user, InventoryCorrection $correction): bool
    {
        return $user->can(InventoryPermission::CorrectionPost->value) && $correction->isDraft();
    }

    public function cancel(User $user, InventoryCorrection $correction): bool
    {
        return $user->can(InventoryPermission::CorrectionCancel->value) && $correction->isDraft();
    }

    public function delete(): bool { return false; }
    public function deleteAny(): bool { return false; }
    public function forceDelete(): bool { return false; }
    public function forceDeleteAny(): bool { return false; }
    public function restore(): bool { return false; }
    public function restoreAny(): bool { return false; }
}
