<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EmployeePermission;
use App\Models\User;
use App\Policies\Concerns\ChecksEmployeePermissions;

final class PlanTaskPolicy
{
    use ChecksEmployeePermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'create');
    }

    public function update(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'update');
    }

    public function delete(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'deleteAny');
    }

    public function restore(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'restoreAny');
    }

    /** @return array<string, string> */
    protected function employeePermissionMap(): array
    {
        return [
            'viewAny' => EmployeePermission::TaskView->value,
            'view' => EmployeePermission::TaskView->value,
            'create' => EmployeePermission::TaskManage->value,
            'update' => EmployeePermission::TaskManage->value,
            'delete' => EmployeePermission::TaskManage->value,
            'deleteAny' => EmployeePermission::TaskManage->value,
            'restore' => EmployeePermission::TaskManage->value,
            'restoreAny' => EmployeePermission::TaskManage->value,
        ];
    }
}
