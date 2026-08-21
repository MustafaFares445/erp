<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EmployeePermission;
use App\Models\User;
use App\Policies\Concerns\ChecksEmployeePermissions;

final class SalesOpportunityPolicy
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
     * Approve/reject the draft (FR-054) — a separate ability from ordinary
     * viewing.
     */
    public function review(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'review');
    }

    /** @return array<string, string> */
    protected function employeePermissionMap(): array
    {
        return [
            'viewAny' => EmployeePermission::OpportunityView->value,
            'view' => EmployeePermission::OpportunityView->value,
            'create' => EmployeePermission::OpportunityReview->value,
            'update' => EmployeePermission::OpportunityReview->value,
            'delete' => EmployeePermission::OpportunityReview->value,
            'deleteAny' => EmployeePermission::OpportunityReview->value,
            'restore' => EmployeePermission::OpportunityReview->value,
            'restoreAny' => EmployeePermission::OpportunityReview->value,
            'review' => EmployeePermission::OpportunityReview->value,
        ];
    }
}
