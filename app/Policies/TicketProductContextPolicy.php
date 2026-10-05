<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class TicketProductContextPolicy
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

    /** The staged flag blocks every ability; historical rows stay intact. */
    private function allows(User $user, string $ability): bool
    {
        return (bool) config('support.product_quality_enabled', false)
            && $this->authorizeSupportAbility($user, $ability);
    }

    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::QualityComplaintView->value,
            'view' => SupportPermission::QualityComplaintView->value,
            'create' => SupportPermission::QualityComplaintManage->value,
        ];
    }
}
