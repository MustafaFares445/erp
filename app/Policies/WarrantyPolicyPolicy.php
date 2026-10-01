<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class WarrantyPolicyPolicy
{
    use ChecksSupportPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'create');
    }

    public function update(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'update');
    }

    public function delete(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'deleteAny');
    }

    public function restore(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'restoreAny');
    }

    /** @return array<string, string> */
    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::WarrantyPolicyView->value,
            'view' => SupportPermission::WarrantyPolicyView->value,
            'create' => SupportPermission::WarrantyPolicyManage->value,
            'update' => SupportPermission::WarrantyPolicyManage->value,
            'delete' => SupportPermission::WarrantyPolicyManage->value,
            'deleteAny' => SupportPermission::WarrantyPolicyManage->value,
            'restore' => SupportPermission::WarrantyPolicyManage->value,
            'restoreAny' => SupportPermission::WarrantyPolicyManage->value,
        ];
    }
}
