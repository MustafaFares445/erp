<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CrmPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;

final class CustomerQuotationRequestPolicy
{
    use ChecksCrmPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'create');
    }

    public function review(User $user): bool
    {
        return $this->authorizeCrmAbility($user, 'review');
    }

    /** @return array<string, string> */
    protected function crmPermissionMap(): array
    {
        return [
            'viewAny' => CrmPermission::QuotationRequestView->value,
            'view' => CrmPermission::QuotationRequestView->value,
            'create' => CrmPermission::QuotationRequestManage->value,
            'review' => CrmPermission::QuotationRequestManage->value,
        ];
    }
}
