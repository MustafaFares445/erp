<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\StockTransfer;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

/**
 * Authorizes {@see StockTransfer} draft→confirm workflow (FI-4).
 *
 * `update`/`delete` are additionally guarded: only a **draft** transfer may
 * be edited or (soft) deleted (FR-017) — a confirmed one is immutable.
 * `create` (prepare) and `confirm` (apply) map to **distinct** permissions,
 * realizing segregation of duties (FR-022/FR-023): an administrator who can
 * prepare drafts may lack the ability to apply them, even for their own
 * draft. Hard delete is never permitted (FR-019).
 *
 * @see /specs/004-stock-transfers/contracts/authorization.md
 */
final class StockTransferPolicy
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

    public function update(User $user, StockTransfer $transfer): bool
    {
        if (! $this->authorizeInventoryAbility($user, 'update')) {
            return false;
        }

        return $transfer->isDraft();
    }

    public function delete(User $user, StockTransfer $transfer): bool
    {
        if (! $this->authorizeInventoryAbility($user, 'delete')) {
            return false;
        }

        return $transfer->isDraft();
    }

    public function restore(User $user): bool
    {
        return $this->authorizeInventoryAbility($user, 'restore');
    }

    public function forceDelete(): bool
    {
        return false;
    }

    /**
     * Apply (confirm) ability — distinct from `create`/`update` (FR-022).
     * Denied once the transfer is no longer a draft (nothing to confirm).
     */
    public function confirm(User $user, StockTransfer $transfer): bool
    {
        if (! $this->authorizeInventoryAbility($user, 'confirm')) {
            return false;
        }

        return $transfer->isDraft();
    }

    /**
     * @return array<string, string>
     */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::TransferView->value,
            'view' => InventoryPermission::TransferView->value,
            'create' => InventoryPermission::TransferCreate->value,
            'update' => InventoryPermission::TransferCreate->value,
            'delete' => InventoryPermission::TransferCreate->value,
            'restore' => InventoryPermission::TransferCreate->value,
            'confirm' => InventoryPermission::TransferConfirm->value,
        ];
    }
}
