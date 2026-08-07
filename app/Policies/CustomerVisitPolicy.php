<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EmployeePermission;
use App\Models\User;
use App\Policies\Concerns\ChecksEmployeePermissions;

final class CustomerVisitPolicy
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

    /**
     * The D7/FR-044 review-note action: usable even on a visit otherwise
     * locked to everyone but a `field-edit` holder.
     */
    public function review(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'review');
    }

    /** @return array<string, string> */
    protected function employeePermissionMap(): array
    {
        return [
            'viewAny' => EmployeePermission::VisitView->value,
            'view' => EmployeePermission::VisitView->value,
            'create' => EmployeePermission::VisitFieldEdit->value,
            'update' => EmployeePermission::VisitFieldEdit->value,
            'delete' => EmployeePermission::VisitFieldEdit->value,
            'deleteAny' => EmployeePermission::VisitFieldEdit->value,
            'restore' => EmployeePermission::VisitFieldEdit->value,
            'restoreAny' => EmployeePermission::VisitFieldEdit->value,
            'review' => EmployeePermission::VisitReview->value,
        ];
    }
}
