<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\InventoryAdjustment;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;

/**
 * Authorizes {@see InventoryAdjustment} draft→confirm workflow (FI-3).
 *
 * `update`/`delete` are additionally guarded: only a **draft** adjustment
 * may be edited or (soft) deleted (FR-016) — a confirmed one is immutable.
 * `create` (prepare) and `confirm` (apply) map to **distinct** permissions,
 * realizing segregation of duties (FR-020/FR-021): an administrator who can
 * prepare drafts may lack the ability to apply them, even for their own
 * draft. Hard delete is never permitted (FR-018).
 *
 * @see /specs/003-stock-adjustments/contracts/authorization.md
 */
final class InventoryAdjustmentPolicy
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

    public function update(User $user, InventoryAdjustment $adjustment): bool
    {
        if (! $this->authorizeInventoryAbility($user, 'update')) {
            return false;
        }

        return $adjustment->isDraft();
    }

    public function delete(User $user, InventoryAdjustment $adjustment): bool
    {
        if (! $this->authorizeInventoryAbility($user, 'delete')) {
            return false;
        }

        return $adjustment->isDraft();
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
     * Apply (confirm) ability — distinct from `create`/`update` (FR-020).
     * Denied once the adjustment is no longer a draft (nothing to confirm).
     */
    public function confirm(User $user, InventoryAdjustment $adjustment): bool
    {
        if (! $this->authorizeInventoryAbility($user, 'confirm')) {
            return false;
        }

        return $adjustment->isDraft();
    }

    /**
     * @return array<string, string>
     */
    protected function inventoryPermissionMap(): array
    {
        return [
            'viewAny' => InventoryPermission::AdjustmentView->value,
            'view' => InventoryPermission::AdjustmentView->value,
            'create' => InventoryPermission::AdjustmentCreate->value,
            'update' => InventoryPermission::AdjustmentCreate->value,
            'delete' => InventoryPermission::AdjustmentCreate->value,
            'restore' => InventoryPermission::AdjustmentCreate->value,
            'confirm' => InventoryPermission::AdjustmentConfirm->value,
        ];
    }
}
