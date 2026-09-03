<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

final class InventoryReservationPolicy
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

    public function release(User $user, \App\Models\InventoryReservation $reservation): bool
    {
        return $reservation->isActive()
            && $this->authorizeInventoryAbility($user, 'release');
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::ReservationView->value,
            'view' => InventoryPermission::ReservationView->value,
            'release' => InventoryPermission::ReservationRelease->value,
        ];
    }
}
