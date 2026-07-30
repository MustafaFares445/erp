<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CrmPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;

final class ProductSubscriptionPolicy
{
    use ChecksCrmPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'create');
    }

    public function update(User $user): bool
    {
        if ($this->authorizeCrmAbility($user, 'update')) {
            return true;
        }

        return $this->authorizeCrmAbility($user, 'updateDiscount');
    }

    public function delete(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'delete');
    }

    public function restore(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'restore');
    }

    public function forceDelete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function crmPermissionMap(): array
    {
        return [
            'viewAny' => CrmPermission::SubscriptionView->value,
            'view' => CrmPermission::SubscriptionView->value,
            'create' => CrmPermission::SubscriptionManage->value,
            'update' => CrmPermission::SubscriptionManage->value,
            'updateDiscount' => CrmPermission::SubscriptionDiscountManage->value,
            'delete' => CrmPermission::SubscriptionManage->value,
            'deleteAny' => CrmPermission::SubscriptionManage->value,
            'restore' => CrmPermission::SubscriptionRestore->value,
            'restoreAny' => CrmPermission::SubscriptionRestore->value,
        ];
    }
}
