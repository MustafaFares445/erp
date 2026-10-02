<?php

declare(strict_types=1);

namespace App\Services\UiPreferences;

use App\Models\User;
use App\Models\UserUiPreference;

final readonly class UserUiPreferenceService
{
    /** @return array<mixed>|null */
    public function get(User $user, string $scope, string $key): ?array
    {
        $value = UserUiPreference::query()
            ->where('user_id', $user->getKey())
            ->where('scope', $scope)
            ->where('key', $key)
            ->value('value');

        return is_array($value) ? $value : null;
    }

    /** @param array<mixed> $value */
    public function put(User $user, string $scope, string $key, array $value): void
    {
        UserUiPreference::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'scope' => $scope, 'key' => $key],
            ['value' => $value],
        );
    }

    public function forget(User $user, string $scope, string $key): void
    {
        UserUiPreference::query()
            ->where('user_id', $user->getKey())
            ->where('scope', $scope)
            ->where('key', $key)
            ->delete();
    }
}
