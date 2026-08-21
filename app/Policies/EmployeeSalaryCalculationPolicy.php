<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EmployeePermission;
use App\Models\User;
use App\Policies\Concerns\ChecksEmployeePermissions;

final class EmployeeSalaryCalculationPolicy
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
     * The FR-065 confirmation step — a separate ability from calculating
     * (`employees.salary.confirm` vs. `employees.salary.calculate`), so a
     * `Reviewer` can never confirm even though they can view.
     */
    public function confirm(User $user): bool
    {
        return $this->authorizeEmployeeAbility($user, 'confirm');
    }

    /** @return array<string, string> */
    protected function employeePermissionMap(): array
    {
        return [
            'viewAny' => EmployeePermission::SalaryView->value,
            'view' => EmployeePermission::SalaryView->value,
            'create' => EmployeePermission::SalaryCalculate->value,
            'update' => EmployeePermission::SalaryCalculate->value,
            'delete' => EmployeePermission::SalaryCalculate->value,
            'deleteAny' => EmployeePermission::SalaryCalculate->value,
            'restore' => EmployeePermission::SalaryCalculate->value,
            'restoreAny' => EmployeePermission::SalaryCalculate->value,
            'confirm' => EmployeePermission::SalaryConfirm->value,
        ];
    }
}
