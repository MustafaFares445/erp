<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\ServiceAppointment;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class ServiceAppointmentPolicy
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

    public function execute(User $user, ServiceAppointment $appointment): bool
    {
        if ($this->authorizeSupportAbility($user, 'update')) {
            return true;
        }

        return $this->authorizeSupportAbility($user, 'execute')
            && $appointment->employee_id === $user->employeeProfile?->getKey();
    }

    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::ServiceAppointmentView->value,
            'view' => SupportPermission::ServiceAppointmentView->value,
            'create' => SupportPermission::ServiceAppointmentManage->value,
            'update' => SupportPermission::ServiceAppointmentManage->value,
            'delete' => SupportPermission::ServiceAppointmentManage->value,
            'execute' => SupportPermission::ServiceAppointmentExecute->value,
        ];
    }
}
