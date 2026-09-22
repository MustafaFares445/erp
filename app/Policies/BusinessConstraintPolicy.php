<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SystemPermission;
use App\Models\User;
use App\Policies\Concerns\ChecksSystemPermissions;
use App\Services\Settings\BusinessConstraintService;

/**
 * Authorization for the business-constraint registry.
 *
 * Managing a constraint is System Admin only, for the reason
 * {@see PurchaseSettingPolicy} already records about the purchasing threshold:
 * whoever can move a limit can approve their own breach by moving the line
 * rather than by breaking a rule. Viewing is separate so a reviewer can audit
 * the configured limits without being able to change them.
 *
 * There is no delete. The singleton-per-key rows are reset through
 * {@see BusinessConstraintService::reset()}, which is an
 * audited restore of the catalogue default rather than a deletion.
 */
final class BusinessConstraintPolicy
{
    use ChecksSystemPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeSystemAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeSystemAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeSystemAbility($user, 'create');
    }

    public function update(User $user): bool
    {
        return $this->authorizeSystemAbility($user, 'update');
    }

    public function delete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function systemPermissionMap(): array
    {
        return [
            'viewAny' => SystemPermission::ConstraintView->value,
            'view' => SystemPermission::ConstraintView->value,
            'create' => SystemPermission::ConstraintManage->value,
            'update' => SystemPermission::ConstraintManage->value,
        ];
    }
}
