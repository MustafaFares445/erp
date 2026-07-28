<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;
use Illuminate\Database\Eloquent\Model;

final class CatalogPolicy
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

    public function create(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'create');
    }

    public function update(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'update');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->authorizeInventoryAbility($user, 'delete') && ! $this->isReferenced($model);
    }

    public function restore(User $user): bool
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
            'viewAny' => InventoryPermission::CatalogView->value,
            'view' => InventoryPermission::CatalogView->value,
            'create' => InventoryPermission::CatalogManage->value,
            'update' => InventoryPermission::CatalogManage->value,
            'delete' => InventoryPermission::CatalogManage->value,
            'restore' => InventoryPermission::CatalogManage->value,
        ];
    }

    private function isReferenced(Model $model): bool
    {
        return match ($model::class) {
            Product::class => $model->variants()->exists(),
            ProductVariant::class => $model->stocks()->exists() || $model->movements()->exists(),
            Unit::class => $model->variants()->exists(),
            Supplier::class => $model->productReferences()->exists() || $model->receipts()->exists(),
            default => false,
        };
    }
}
