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
        return $this->authorizeEmployeeAbility($user, 'manage');
    }

    public function schedule(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'manage');
    }

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
            'manage' => EmployeePermission::VisitManage->value,
            'review' => EmployeePermission::VisitReview->value,
        ];
    }
}
