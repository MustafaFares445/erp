<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\CrmPermission;
use App\Enums\UserType;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

final readonly class DashboardRoleAssignmentService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function assign(User $user, string $roleName, User $actor): User
    {
        $this->authorize($actor);

        if (! in_array($roleName, CrmPermission::fixedRoleNames(), true)) {
            throw new DomainException(__('admin.crm.errors.fixed_role_only'));
        }

        return DB::transaction(function () use ($user, $roleName, $actor): User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($lockedUser->user_type !== UserType::Admin) {
                throw new DomainException(__('admin.crm.errors.dashboard_user_only'));
            }

            $role = Role::query()->where('guard_name', 'web')->where('name', $roleName)->first();

            if (! $role instanceof Role) {
                throw new DomainException('The requested fixed dashboard role is not available.');
            }

            $oldRoles = $lockedUser->roles()->pluck('name')->all();
            $lockedUser->syncRoles([$role->name]);
            $newRoles = [$role->name];

            if ($oldRoles !== $newRoles) {
                $this->auditLogger->log(
                    action: 'identity.dashboard_roles.assigned',
                    entity: $lockedUser,
                    oldValues: ['roles' => $oldRoles],
                    newValues: ['roles' => $newRoles],
                    actor: $actor,
                );
            }

            return $lockedUser->refresh()->load('roles');
        }, attempts: 5);
    }

    private function authorize(User $actor): void
    {
        if ($actor->isAdmin() && ! $actor->hasAnyRole(CrmPermission::fixedRoleNames())) {
            return;
        }

        Gate::forUser($actor)->authorize(CrmPermission::DashboardRoleAssign->value);
    }
}
