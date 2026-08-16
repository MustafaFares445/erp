<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CrmPermission;
use App\Enums\DashboardRole;
use App\Enums\SupportPermission;
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

    /**
     * `support.audit.view` (spec 016, ADR 0004) is an additional valid
     * credential alongside the original `crm.audit.view` — there is no
     * Support-specific audit mechanism; both modules share this one
     * `AuditLogResource` (contracts/audit-log.md).
     */
    private function canViewAudit(User $user): bool
    {
        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        if ($user->can(CrmPermission::AuditView->value)) {
            return true;
        }

        return $user->can(SupportPermission::AuditView->value);
    }
}
