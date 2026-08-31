<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

final class InventoryReservationPolicy
{
    use ChecksInventoryPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'viewAny');
    }

    public function view(User $user, InventoryReservation $reservation): bool
    {
        return $this->authorizeInventoryAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, InventoryReservation $reservation): bool
    {
        return false;
    }

    public function delete(User $user, InventoryReservation $reservation): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::ReservationView->value,
            'view' => InventoryPermission::ReservationView->value,
        ];
    }
}
