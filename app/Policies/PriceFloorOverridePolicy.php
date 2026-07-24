<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

final class PriceFloorOverridePolicy
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

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }

    public function restore(): bool
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
            'viewAny' => InventoryPermission::PricingView->value,
            'view' => InventoryPermission::PricingView->value,
        ];
    }
}
