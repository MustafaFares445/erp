<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CrmPermission;
use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Models\User;

final class CustomerPricingTierPolicy
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
        return $this->allows($user, InventoryPermission::PricingManage, CrmPermission::PricingTierLinkManage);
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
