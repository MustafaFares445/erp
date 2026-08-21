<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class MaintenanceTaskPolicy
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

    /**
     * Manager-unrestricted create/assign/due-date/transition (FR-070).
     */
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

    /**
     * Restoration is System-Admin-only (FR-001, User Story 1 scenario 2) —
     * never granted to Support Manager, unlike ordinary service-record
     * management.
     */
    public function restore(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'restoreAny');
    }

    /**
     * Manager-unrestricted transition rights on any service record, OR the
     * assigned Support Agent transitioning their own (FR-075).
     */
    public function execute(User $user, MaintenanceTask $task): bool
    {
        if ($this->authorizeSupportAbility($user, 'update')) {
            return true;
        }

        return $this->authorizeSupportAbility($user, 'execute')
            && $task->employee_id !== null
            && $task->employee_id === $user->employeeProfile?->getKey();
    }

    /**
     * Manager-unrestricted parts consumption on any service record, OR the
     * assigned Support Agent consuming against their own (FR-081,
     * permissions.md).
     */
    public function consume(User $user, MaintenanceTask $task): bool
    {
        if ($this->authorizeSupportAbility($user, 'update')) {
            return true;
        }

        return $this->authorizeSupportAbility($user, 'consume')
            && $task->employee_id !== null
            && $task->employee_id === $user->employeeProfile?->getKey();
    }

    /**
     * System-Admin-only, unconditionally — never granted to Support
     * Manager (permissions.md, FR-086).
     */
    public function reverse(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'reverse');
    }

    /** @return array<string, string> */
    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::ServiceRecordView->value,
            'view' => SupportPermission::ServiceRecordView->value,
            'create' => SupportPermission::ServiceRecordManage->value,
            'update' => SupportPermission::ServiceRecordManage->value,
            'delete' => SupportPermission::ServiceRecordManage->value,
            'deleteAny' => SupportPermission::ServiceRecordManage->value,
            'restore' => SupportPermission::RecordRestore->value,
            'restoreAny' => SupportPermission::RecordRestore->value,
            'execute' => SupportPermission::ServiceRecordExecute->value,
            'consume' => SupportPermission::PartsConsume->value,
            'reverse' => SupportPermission::PartsReverse->value,
        ];
    }
}
