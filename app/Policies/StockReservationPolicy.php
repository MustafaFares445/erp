<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\StockReservation;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

final class StockReservationPolicy
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

    public function release(User $user, StockReservation $reservation): bool
    {
        return $this->authorizeInventoryAbility($user, 'release') && $reservation->isReleasable();
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
