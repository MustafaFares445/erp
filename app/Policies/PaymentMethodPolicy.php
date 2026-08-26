<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SalesPermission;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Policies\Concerns\ChecksSalesPermissions;

final class PaymentMethodPolicy
{
    use ChecksSalesPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'viewAny');
    }

    public function view(User $user, PaymentMethod $paymentMethod): bool
    {
        return $this->authorizeSalesAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'create');
    }

    public function update(User $user, PaymentMethod $paymentMethod): bool
    {
        return $this->authorizeSalesAbility($user, 'update');
    }

    /** @return array<string, string> */
    protected function salesPermissionMap(): array
    {
        return [
            'viewAny' => SalesPermission::PaymentMethodView->value,
            'view' => SalesPermission::PaymentMethodView->value,
            'create' => SalesPermission::PaymentMethodManage->value,
            'update' => SalesPermission::PaymentMethodManage->value,
        ];
    }
}
