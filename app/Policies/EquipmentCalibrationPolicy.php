<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class EquipmentCalibrationPolicy
{
    use ChecksSupportPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    /** Recording checks and evidence is open to anyone who may manage or complete calibrations. */
    public function update(User $user): bool
    {
        if ($this->allows($user, 'update')) {
            return true;
        }

        return $this->allows($user, 'complete');
    }

    public function complete(User $user): bool
    {
        return $this->allows($user, 'complete');
    }

    /** The staged flag blocks every ability; historical rows stay intact. */
    private function allows(User $user, string $ability): bool
    {
        return (bool) config('support.calibration_enabled', true)
            && $this->authorizeSupportAbility($user, $ability);
    }

    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::CalibrationView->value,
            'view' => SupportPermission::CalibrationView->value,
            'create' => SupportPermission::CalibrationManage->value,
            'update' => SupportPermission::CalibrationManage->value,
            'complete' => SupportPermission::CalibrationComplete->value,
        ];
    }
}
