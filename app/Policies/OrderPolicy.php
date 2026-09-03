<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Enums\SalesPermission;
use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->can(InventoryPermission::DeliveryView->value)) {
            return true;
        }
        return $user->can(SalesPermission::OrderView->value);
    }

    public function view(User $user): bool
    {
        if ($user->can(InventoryPermission::DeliveryView->value)) {
            return true;
        }
        return $user->can(SalesPermission::OrderView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(InventoryPermission::DeliveryCreate->value);
    }

    public function update(User $user): bool
    {
        return $user->can(SalesPermission::OrderManage->value);
    }

    public function fulfill(User $user, Order $order): bool
    {
        return ! $order->deliveries()->where('stage', '!=', 'canceled')->exists()
            && ! $order->procurementRequirements()
                ->whereNotIn('status', ['fulfilled', 'cancelled'])
                ->exists()
            && $user->can(InventoryPermission::DeliveryCreate->value);
    }
}
