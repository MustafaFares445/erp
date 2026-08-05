<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\Shipment;
use App\Models\User;

final class ShipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(InventoryPermission::ShipmentView->value);
    }

    public function view(User $user, Shipment $shipment): bool
    {
        return $this->viewAny($user);
    }

    public function confirm(User $user, Shipment $shipment): bool
    {
        return $user->can(InventoryPermission::ShipmentConfirm->value) && $shipment->isInTransit();
    }
}
