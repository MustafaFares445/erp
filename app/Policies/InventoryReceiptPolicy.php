<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\InventoryReceipt;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

final class InventoryReceiptPolicy
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

    public function update(User $user, InventoryReceipt $receipt): bool
    {
        return $this->authorizeInventoryAbility($user, 'update') && $receipt->isDraft();
    }

    public function delete(User $user, InventoryReceipt $receipt): bool
    {
        return $this->authorizeInventoryAbility($user, 'delete') && $receipt->isDraft();
    }

    public function restore(User $user, InventoryReceipt $receipt): bool
    {
        return $this->authorizeInventoryAbility($user, 'restore') && $receipt->isDraft();
    }

    public function confirm(User $user, InventoryReceipt $receipt): bool
    {
        return $this->authorizeInventoryAbility($user, 'confirm') && $receipt->isDraft();
    }

    public function forceDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::ReceiptView->value,
            'view' => InventoryPermission::ReceiptView->value,
            'create' => InventoryPermission::ReceiptCreate->value,
            'update' => InventoryPermission::ReceiptCreate->value,
            'delete' => InventoryPermission::ReceiptCreate->value,
            'restore' => InventoryPermission::ReceiptCreate->value,
            'confirm' => InventoryPermission::ReceiptConfirm->value,
        ];
    }
}
