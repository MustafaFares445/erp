<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\ProductVariant;
use App\Models\User;

final class ProductVariantPolicy
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

    public function delete(User $user, ProductVariant $variant): bool
    {
        return $this->canManageCatalog($user)
            && ! $variant->stocks()->exists()
            && ! $variant->movements()->exists();
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
        return $user->can(InventoryPermission::ProductView->value)
            || $user->can(InventoryPermission::CatalogView->value);
    }

    private function canManageCatalog(User $user): bool
    {
        return $user->can(InventoryPermission::ProductManage->value)
            || $user->can(InventoryPermission::CatalogManage->value);
    }
}
