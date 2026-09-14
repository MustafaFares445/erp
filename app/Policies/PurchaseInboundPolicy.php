<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

final class PurchaseInboundPolicy
{
    use ChecksInventoryPermissions;

    public function viewAny(User $user): bool
    {
        if ($user->can(InventoryPermission::ReceiptView->value)) {
            return true;
        }

        return $user->can(InventoryPermission::InboundAllocate->value);
    }

    public function view(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'view');
    }

    public function allocate(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'allocate');
    }

    public function receive(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'receive');
    }

    /** @return array<string, string> */
    protected function inventoryPermissionMap(): array
    {
        return [
            'view' => InventoryPermission::ReceiptView->value,
            'allocate' => InventoryPermission::InboundAllocate->value,
            'receive' => InventoryPermission::ReceiptCreate->value,
        ];
    }
}
