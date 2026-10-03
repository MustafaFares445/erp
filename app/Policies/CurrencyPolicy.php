<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Models\Currency;
use App\Models\User;

/**
 * The currency catalogue is Accounting-owned master data, so it is gated on
 * `accounting.currency.*`.
 */
final class CurrencyPolicy
{
    private function canView(User $user): bool
    {
        return $user->can(AccountingPermission::CurrencyView->value) || $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $user->can(AccountingPermission::CurrencyManage->value);
    }

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Currency $currency): bool
    {
        return ! $currency->is_default && $this->canManage($user);
    }
}
