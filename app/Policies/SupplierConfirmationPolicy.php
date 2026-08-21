<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PurchasePermission;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Policies\Concerns\ChecksPurchasePermissions;

/**
 * Supplier confirmation authorization.
 *
 * `update` and `delete` are permanently false, not merely permission-gated: a
 * confirmation is the supplier's recorded answer, and correcting it means
 * appending a new one (R-E, FR-031). `answer` covers moving a pending record
 * to confirmed or rejected, which is the only mutation the record ever accepts
 * — and only while it is still pending.
 */
final class SupplierConfirmationPolicy
{
    use ChecksPurchasePermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'create');
    }

    public function answer(User $user, SupplierConfirmation $confirmation): bool
    {
        return ! $confirmation->isAnswered()
            && $this->authorizePurchaseAbility($user, 'answer');
    }

    /**
     * Never permitted. Editing an answered confirmation would overwrite the
     * evidence the receiving-performance report is built from.
     */
    public function update(): bool
    {
        return false;
    }

    /** Never permitted, for the same reason as {@see self::update()}. */
    public function delete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function purchasePermissionMap(): array
    {
        return [
            'viewAny' => PurchasePermission::ConfirmationView->value,
            'view' => PurchasePermission::ConfirmationView->value,
            'create' => PurchasePermission::ConfirmationRecord->value,
            'answer' => PurchasePermission::ConfirmationRecord->value,
        ];
    }
}
