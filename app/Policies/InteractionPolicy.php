<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CrmPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;

final class InteractionPolicy
{
    use ChecksCrmPermissions;

    public function viewAny(User $user): bool { return $this->authorizeCrmAbility($user, 'viewAny'); }
    public function view(User $user): bool { return $this->authorizeCrmAbility($user, 'view'); }
    public function create(User $user): bool { return $this->authorizeCrmAbility($user, 'create'); }
    public function update(): bool { return false; }
    public function delete(): bool { return false; }

    /** @return array<string, string> */
    protected function crmPermissionMap(): array
    {
        return [
            'viewAny' => CrmPermission::InteractionView->value,
            'view' => CrmPermission::InteractionView->value,
            'create' => CrmPermission::InteractionCreate->value,
        ];
    }
}
