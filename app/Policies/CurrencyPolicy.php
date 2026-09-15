<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PurchasePermission;
use App\Models\Currency;
use App\Models\User;

final class CurrencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PurchasePermission::SettingManage->value);
    }

    public function view(User $user, Currency $currency): bool
    {
        return $user->can(PurchasePermission::SettingManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PurchasePermission::SettingManage->value);
    }

    public function update(User $user, Currency $currency): bool
    {
        return $user->can(PurchasePermission::SettingManage->value);
    }

    public function delete(User $user, Currency $currency): bool
    {
        return ! $currency->is_default && $user->can(PurchasePermission::SettingManage->value);
    }
}
