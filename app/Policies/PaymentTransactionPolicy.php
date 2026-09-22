<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SalesPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSalesPermissions;

final class PaymentTransactionPolicy
{
    use ChecksSalesPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'view');
    }

    public function reconcile(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'reconcile');
    }

    /** @return array<string, string> */
    protected function salesPermissionMap(): array
    {
        return [
            'viewAny' => SalesPermission::PaymentTransactionView->value,
            'view' => SalesPermission::PaymentTransactionView->value,
            'reconcile' => SalesPermission::PaymentTransactionReconcile->value,
        ];
    }
}
