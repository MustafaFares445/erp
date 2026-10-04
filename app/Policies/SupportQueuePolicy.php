<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class SupportQueuePolicy
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

    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::QueueView->value,
            'view' => SupportPermission::QueueView->value,
            'create' => SupportPermission::QueueManage->value,
            'update' => SupportPermission::QueueManage->value,
            'delete' => SupportPermission::QueueManage->value,
        ];
    }
}
