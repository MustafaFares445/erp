<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Enums\OrderStatus;
use App\Enums\SalesPermission;
use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(InventoryPermission::DeliveryView->value)
            || $user->can(SalesPermission::OrderView->value);
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can(InventoryPermission::DeliveryView->value)
            || $user->can(SalesPermission::OrderView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(SalesPermission::OrderCreate->value);
    }

    public function update(User $user, Order $order): bool
    {
        return $order->status === OrderStatus::Draft
            && $user->can(SalesPermission::OrderManage->value);
    }

    public function confirm(User $user, Order $order): bool
    {
        return $order->status === OrderStatus::Draft
            && $user->can(SalesPermission::OrderConfirm->value);
    }

    public function release(User $user, Order $order): bool
    {
        return $order->status === OrderStatus::Confirmed
            && $user->can(SalesPermission::OrderRelease->value);
    }

    public function cancel(User $user, Order $order): bool
    {
        return ! $order->status->isTerminal()
            && $user->can(SalesPermission::OrderCancel->value);
    }

    public function close(User $user, Order $order): bool
    {
        return $order->status === OrderStatus::Released
            && $user->can(SalesPermission::OrderClose->value);
    }

    public function planFulfillment(User $user, Order $order): bool
    {
        return $order->status === OrderStatus::Released
            && $user->can(InventoryPermission::DeliveryCreate->value);
    }

    /** @deprecated Use planFulfillment(). */
    public function fulfill(User $user, Order $order): bool
    {
        return $this->planFulfillment($user, $order);
    }
}
