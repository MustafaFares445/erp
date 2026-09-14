<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\ProductVariant;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

final class ProductVariantPolicy
{
    use ChecksInventoryPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'viewAny');
    }

    public function view(User $user, ProductVariant $variant): bool
    {
        return $this->authorizeInventoryAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'create');
    }

    public function update(User $user, ProductVariant $variant): bool
    {
        return $this->authorizeInventoryAbility($user, 'update');
    }

    public function delete(User $user, ProductVariant $variant): bool
    {
        return $this->authorizeInventoryAbility($user, 'delete')
            && ! $variant->stocks()->exists()
            && ! $variant->movements()->exists();
    }

    public function restore(User $user, ProductVariant $variant): bool
    {
        return $this->authorizeInventoryAbility($user, 'restore');
    }

    public function forceDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::ProductView->value,
            'view' => InventoryPermission::ProductView->value,
            'create' => InventoryPermission::ProductManage->value,
            'update' => InventoryPermission::ProductManage->value,
            'delete' => InventoryPermission::ProductManage->value,
            'restore' => InventoryPermission::ProductManage->value,
        ];
    }
}
