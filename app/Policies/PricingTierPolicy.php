<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CrmPermission;
use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Models\User;

final class PricingTierPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, InventoryPermission::PricingView, CrmPermission::PricingTierView);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, InventoryPermission::PricingManage, CrmPermission::PricingTierManage);
    }

    public function update(User $user): bool
    {
        return $this->create($user);
    }

    public function delete(User $user): bool
    {
        return $this->create($user);
    }

    public function restore(User $user): bool
    {
        return $this->allows($user, InventoryPermission::PricingManage, CrmPermission::PricingTierRestore);
    }

    public function forceDelete(): bool
    {
        return false;
    }

    public function updateDiscount(User $user): bool
    {
        return $this->allows($user, InventoryPermission::PricingManage, CrmPermission::PricingTierDiscountManage);
    }

    public function manageLinks(User $user): bool
    {
        return $this->allows($user, InventoryPermission::PricingManage, CrmPermission::PricingTierLinkManage);
    }

    private function allows(User $user, InventoryPermission $inventoryPermission, CrmPermission $crmPermission): bool
    {
        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        if ($user->can($inventoryPermission->value)) {
            return true;
        }

        return $user->can($crmPermission->value);
    }
}
