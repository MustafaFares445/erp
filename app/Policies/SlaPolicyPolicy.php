<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

/**
 * List + Edit only (data-model.md §5 — 4 fixed rows, no Create/Delete), so
 * only the read/update abilities are ever consulted.
 */
final class SlaPolicyPolicy
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

    public function update(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'update');
    }

    /** @return array<string, string> */
    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::SlaPolicyView->value,
            'view' => SupportPermission::SlaPolicyView->value,
            'update' => SupportPermission::SlaPolicyManage->value,
        ];
    }
}
