<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class MaintenanceSchedulePolicy
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

    /** @return array<string, string> */
    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::MaintenanceScheduleView->value,
            'view' => SupportPermission::MaintenanceScheduleView->value,
            'create' => SupportPermission::MaintenanceScheduleManage->value,
            'update' => SupportPermission::MaintenanceScheduleManage->value,
            'delete' => SupportPermission::MaintenanceScheduleManage->value,
            'deleteAny' => SupportPermission::MaintenanceScheduleManage->value,
        ];
    }
}
