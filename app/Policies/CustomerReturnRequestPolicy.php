<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CrmPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;

final class CustomerReturnRequestPolicy
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

    public function review(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'review');
    }

    /** @return array<string, string> */
    protected function crmPermissionMap(): array
    {
        return [
            'viewAny' => CrmPermission::ReturnRequestView->value,
            'view' => CrmPermission::ReturnRequestView->value,
            'create' => CrmPermission::ReturnRequestManage->value,
            'review' => CrmPermission::ReturnRequestManage->value,
        ];
    }
}
