<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CrmPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;

final class LeadPolicy
{
    use ChecksCrmPermissions;

    public function viewAny(User $user): bool { return $this->authorizeCrmAbility($user, 'viewAny'); }
    public function view(User $user): bool { return $this->authorizeCrmAbility($user, 'view'); }
    public function create(User $user): bool { return $this->authorizeCrmAbility($user, 'create'); }
    public function update(User $user): bool { return $this->authorizeCrmAbility($user, 'update'); }
    public function delete(User $user): bool { return $this->authorizeCrmAbility($user, 'delete'); }
    public function restore(User $user): bool { return $this->authorizeCrmAbility($user, 'restore'); }
    public function assign(User $user): bool { return $this->authorizeCrmAbility($user, 'assign'); }
    public function convert(User $user): bool { return $this->authorizeCrmAbility($user, 'convert'); }
    public function forceDelete(): bool { return false; }

    /** @return array<string, string> */
    protected function crmPermissionMap(): array
    {
        return [
            'viewAny' => CrmPermission::LeadView->value,
            'view' => CrmPermission::LeadView->value,
            'create' => CrmPermission::LeadCreate->value,
            'update' => CrmPermission::LeadUpdate->value,
            'delete' => CrmPermission::LeadUpdate->value,
            'restore' => CrmPermission::LeadUpdate->value,
            'assign' => CrmPermission::LeadAssign->value,
            'convert' => CrmPermission::LeadConvert->value,
        ];
    }
}
