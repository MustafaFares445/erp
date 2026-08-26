<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PurchasePermission;
use App\Enums\SalesPermission;
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

    public function request(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'create')
            || $user->can(SalesPermission::SupplierConfirmationRequest->value);
    }

    public function answer(User $user, SupplierConfirmation $confirmation): bool
    {
        if (! $this->authorizePurchaseAbility($user, 'answer')) {
            return false;
        }

        if (! $confirmation->items()->exists()) {
            return ! $confirmation->isAnswered();
        }

        return $confirmation->items()
            ->where('confirmation_status', 'pending')
            ->exists();
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
