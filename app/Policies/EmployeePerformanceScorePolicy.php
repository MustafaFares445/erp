<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EmployeePermission;
use App\Models\User;
use App\Policies\Concerns\ChecksEmployeePermissions;

final class EmployeePerformanceScorePolicy
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

    public function recalculate(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'recalculate');
    }

    /** @return array<string, string> */
    protected function employeePermissionMap(): array
    {
        return [
            'viewAny' => EmployeePermission::PerformanceView->value,
            'view' => EmployeePermission::PerformanceView->value,
            'create' => EmployeePermission::PerformanceRecalculate->value,
            'update' => EmployeePermission::PerformanceRecalculate->value,
            'delete' => EmployeePermission::PerformanceRecalculate->value,
            'deleteAny' => EmployeePermission::PerformanceRecalculate->value,
            'restore' => EmployeePermission::PerformanceRecalculate->value,
            'restoreAny' => EmployeePermission::PerformanceRecalculate->value,
            'recalculate' => EmployeePermission::PerformanceRecalculate->value,
        ];
    }
}
