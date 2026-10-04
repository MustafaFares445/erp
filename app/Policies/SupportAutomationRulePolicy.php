<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class SupportAutomationRulePolicy
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

    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::AutomationView->value,
            'view' => SupportPermission::AutomationView->value,
            'create' => SupportPermission::AutomationManage->value,
            'update' => SupportPermission::AutomationManage->value,
            'delete' => SupportPermission::AutomationManage->value,
        ];
    }
}
