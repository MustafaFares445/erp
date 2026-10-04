<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class EquipmentLoanPolicy
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

    public function update(User $user): bool
    {
        return $this->allows($user, 'update');
    }

    /** The staged flag blocks every ability; historical rows stay intact. */
    private function allows(User $user, string $ability): bool
    {
        return (bool) config('support.loaner_equipment_enabled', false)
            && $this->authorizeSupportAbility($user, $ability);
    }

    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::LoanView->value,
            'view' => SupportPermission::LoanView->value,
            'create' => SupportPermission::LoanManage->value,
            'update' => SupportPermission::LoanManage->value,
        ];
    }
}
