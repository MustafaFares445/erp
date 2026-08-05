<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CrmPermission;
use App\Enums\DashboardRole;
use App\Models\AuditLog;
use App\Models\User;

final class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canViewAudit($user);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $auditLog->exists && $this->canViewAudit($user);
    }

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }

    public function restore(): bool
    {
        return false;
    }

    public function forceDelete(): bool
    {
        return false;
    }

    private function canViewAudit(User $user): bool
    {
        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can(CrmPermission::AuditView->value);
    }
}
