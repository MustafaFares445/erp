<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EmployeePermission;
use App\Models\User;
use App\Policies\Concerns\ChecksEmployeePermissions;

final class BonusSuggestionPolicy
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

    public function approve(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'approve');
    }

    /** @return array<string, string> */
    protected function employeePermissionMap(): array
    {
        return [
            'viewAny' => EmployeePermission::BonusView->value,
            'view' => EmployeePermission::BonusView->value,
            'create' => EmployeePermission::BonusApprove->value,
            'update' => EmployeePermission::BonusApprove->value,
            'delete' => EmployeePermission::BonusApprove->value,
            'deleteAny' => EmployeePermission::BonusApprove->value,
            'restore' => EmployeePermission::BonusApprove->value,
            'restoreAny' => EmployeePermission::BonusApprove->value,
            'approve' => EmployeePermission::BonusApprove->value,
        ];
    }
}
