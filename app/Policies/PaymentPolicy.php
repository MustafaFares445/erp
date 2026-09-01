<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SalesPermission;
use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksSalesPermissions;

final class PaymentPolicy
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

    public function create(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'create');
    }

    public function update(User $user, Payment $payment): bool
    {
        return ! $payment->isPosted()
            && $this->authorizeSalesAbility($user, 'update');
    }

    public function delete(User $user, Payment $payment): bool
    {
        return ! $payment->isPosted()
            && $this->authorizeSalesAbility($user, 'delete');
    }

    /** @return array<string, string> */
    protected function salesPermissionMap(): array
    {
        return [
            'viewAny' => SalesPermission::PaymentView->value,
            'view' => SalesPermission::PaymentView->value,
            'create' => SalesPermission::PaymentRecord->value,
            'update' => SalesPermission::PaymentRecord->value,
            'delete' => SalesPermission::PaymentRecord->value,
        ];
    }
}
