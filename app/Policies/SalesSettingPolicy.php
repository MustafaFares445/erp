<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SalesPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSalesPermissions;

/**
 * Sales settings authorization.
 *
 * `sales.setting.manage` is System Admin only (contracts/permissions.md §2):
 * the four posting accounts and the default tax rate are accounting
 * decisions wearing a sales label, not something a Sales or Billing role
 * should be able to move on their own.
 *
 * There is no delete: the settings row is a singleton, and deleting it would
 * silently restore the zero-tax, no-posting-account default — a
 * configuration change disguised as a deletion, exactly as
 * {@see PurchaseSettingPolicy} already established for `purchase_settings`.
 */
final class SalesSettingPolicy
{
    use ChecksSalesPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'create');
    }

    public function update(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'update');
    }

    public function delete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function salesPermissionMap(): array
    {
        return [
            'viewAny' => SalesPermission::SalesSettingView->value,
            'view' => SalesPermission::SalesSettingView->value,
            'create' => SalesPermission::SalesSettingManage->value,
            'update' => SalesPermission::SalesSettingManage->value,
        ];
    }
}
