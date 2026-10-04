<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class SlaCalendarPolicy
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

    /** @return array<string, string> */
    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::SlaCalendarView->value,
            'view' => SupportPermission::SlaCalendarView->value,
            'create' => SupportPermission::SlaCalendarManage->value,
            'update' => SupportPermission::SlaCalendarManage->value,
            'delete' => SupportPermission::SlaCalendarManage->value,
        ];
    }
}
