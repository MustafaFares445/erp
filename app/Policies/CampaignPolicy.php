<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CrmPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;

final class CampaignPolicy
{
    use ChecksCrmPermissions;

    public function viewAny(User $user): bool { return $this->authorizeCrmAbility($user, 'viewAny'); }
    public function view(User $user): bool { return $this->authorizeCrmAbility($user, 'view'); }
    public function create(User $user): bool { return $this->authorizeCrmAbility($user, 'create'); }
    public function update(User $user): bool { return $this->authorizeCrmAbility($user, 'update'); }
    public function delete(User $user): bool { return $this->authorizeCrmAbility($user, 'delete'); }
    public function restore(User $user): bool { return $this->authorizeCrmAbility($user, 'restore'); }
    public function send(User $user): bool { return $this->authorizeCrmAbility($user, 'send'); }
    public function forceDelete(): bool { return false; }

    /** @return array<string, string> */
    protected function crmPermissionMap(): array
    {
        return [
            'viewAny' => CrmPermission::CampaignView->value,
            'view' => CrmPermission::CampaignView->value,
            'create' => CrmPermission::CampaignManage->value,
            'update' => CrmPermission::CampaignManage->value,
            'delete' => CrmPermission::CampaignManage->value,
            'restore' => CrmPermission::CampaignManage->value,
            'send' => CrmPermission::CampaignSend->value,
        ];
    }
}
