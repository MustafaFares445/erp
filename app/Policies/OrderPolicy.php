<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Enums\SalesPermission;
use App\Models\User;

/**
 * Shared between two modules: Inventory reaches this order to fulfil it,
 * Sales reaches it to read and edit its commercial detail
 * (contracts/permissions.md §3). `viewAny`/`view` therefore OR the two
 * permission sources rather than replacing one with the other — neither
 * module's holder loses access the other module never granted.
 */
final class OrderPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        if ($user->can(InventoryPermission::DeliveryView->value)) {
            return true;
        }

        return $user->can(SalesPermission::OrderView->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user): bool
    {
        if ($user->can(InventoryPermission::DeliveryView->value)) {
            return true;
        }

        return $user->can(SalesPermission::OrderView->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can(InventoryPermission::DeliveryCreate->value);
    }

    /**
     * Determine whether the user can update the order's commercial detail.
     */
    public function update(User $user): bool
    {
        return $user->can(SalesPermission::OrderManage->value);
    }
}
