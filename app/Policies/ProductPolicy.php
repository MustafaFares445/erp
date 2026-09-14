<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\Product;
use App\Models\User;

final class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewCatalog($user);
    }

    public function view(User $user): bool
    {
        return $this->canViewCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function update(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->canManageCatalog($user)
            && ! $product->variants()->exists();
    }

    public function restore(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function forceDelete(): bool
    {
        return false;
    }

    public function forceDeleteAny(): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function replicate(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    public function reorder(User $user): bool
    {
        return $this->canManageCatalog($user);
    }

    private function canViewCatalog(User $user): bool
    {
        if ($user->can(InventoryPermission::ProductView->value)) {
            return true;
        }

        return $user->can(InventoryPermission::CatalogView->value);
    }

    private function canManageCatalog(User $user): bool
    {
        if ($user->can(InventoryPermission::ProductManage->value)) {
            return true;
        }

        return $user->can(InventoryPermission::CatalogManage->value);
    }
}
