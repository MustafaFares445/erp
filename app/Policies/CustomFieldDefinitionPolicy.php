<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SystemPermission;
use App\Models\CustomFieldDefinition;
use App\Models\User;

final class CustomFieldDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(SystemPermission::CustomFieldManage->value);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CustomFieldDefinition $definition): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, CustomFieldDefinition $definition): bool
    {
        return $this->viewAny($user) && $definition->values()->doesntExist();
    }

    public function restore(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function forceDelete(): bool
    {
        return false;
    }
}
