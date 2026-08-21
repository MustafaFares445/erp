<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class MaintenanceRecordPolicy
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

    /**
     * Restoration is System-Admin-only (FR-001, User Story 1 scenario 2) —
     * never granted to Support Manager, unlike ordinary maintenance-request
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

    /** @return array<string, string> */
    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::MaintenanceRequestView->value,
            'view' => SupportPermission::MaintenanceRequestView->value,
            'create' => SupportPermission::MaintenanceRequestManage->value,
            'update' => SupportPermission::MaintenanceRequestManage->value,
            'delete' => SupportPermission::MaintenanceRequestManage->value,
            'deleteAny' => SupportPermission::MaintenanceRequestManage->value,
            'restore' => SupportPermission::RecordRestore->value,
            'restoreAny' => SupportPermission::RecordRestore->value,
        ];
    }
}
