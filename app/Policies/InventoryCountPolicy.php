<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\InventoryCount;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

final class InventoryCountPolicy
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

    public function record(User $user, InventoryCount $count): bool
    {
        return $this->authorizeInventoryAbility($user, 'record')
            && in_array($count->status->value, ['draft', 'counting'], true);
    }

    public function confirm(User $user, InventoryCount $count): bool
    {
        return $this->authorizeInventoryAbility($user, 'confirm')
            && $count->isPendingReview();
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }

    public function forceDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::CountView->value,
            'view' => InventoryPermission::CountView->value,
            'create' => InventoryPermission::CountOpen->value,
            'record' => InventoryPermission::CountRecord->value,
            'confirm' => InventoryPermission::CountConfirm->value,
        ];
    }
}
