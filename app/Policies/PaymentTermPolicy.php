<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SalesPermission;
use App\Models\PaymentTerm;
use App\Models\User;
use App\Policies\Concerns\ChecksSalesPermissions;
use App\Services\Sales\PaymentTermService;

/**
 * Payment term authorization.
 *
 * `delete` checks only the permission for now — the reference guard
 * (FR-012) lives in {@see PaymentTermService::delete()},
 * which cannot yet check `Quotation` or `Invoice` references because neither
 * model exists at this point in the build. Both arrive later in this feature
 * and extend that service method; this policy needs no change when they do,
 * since the service throws before a refused deletion ever reaches here.
 */
final class PaymentTermPolicy
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

    public function update(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'update');
    }

    public function delete(User $user, PaymentTerm $term): bool
    {
        return $this->authorizeSalesAbility($user, 'delete');
    }

    /** @return array<string, string> */
    protected function salesPermissionMap(): array
    {
        return [
            'viewAny' => SalesPermission::PaymentTermView->value,
            'view' => SalesPermission::PaymentTermView->value,
            'create' => SalesPermission::PaymentTermManage->value,
            'update' => SalesPermission::PaymentTermManage->value,
            'delete' => SalesPermission::PaymentTermManage->value,
        ];
    }
}
