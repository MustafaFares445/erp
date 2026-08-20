<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PurchasePermission;
use App\Models\User;
use App\Policies\Concerns\ChecksPurchasePermissions;

/**
 * Approval-threshold authorization.
 *
 * `purchase.setting.manage` is granted to System Admin alone
 * (contracts/permissions.md §2). The threshold decides which orders need a
 * second pair of eyes, so a Purchasing Manager who could raise it could
 * approve their own spending by moving the line rather than by breaking a rule.
 *
 * There is no delete: the settings row is a singleton and deleting it would
 * silently restore the zero default, which is a threshold change disguised as
 * a deletion.
 */
final class PurchaseSettingPolicy
{
    use ChecksPurchasePermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'create');
    }

    public function update(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'update');
    }

    public function delete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function purchasePermissionMap(): array
    {
        return [
            'viewAny' => PurchasePermission::SettingManage->value,
            'view' => PurchasePermission::SettingManage->value,
            'create' => PurchasePermission::SettingManage->value,
            'update' => PurchasePermission::SettingManage->value,
        ];
    }
}
