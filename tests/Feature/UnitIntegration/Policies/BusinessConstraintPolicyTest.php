<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\SystemPermission;
use App\Models\User;
use App\Policies\BusinessConstraintPolicy;
use App\Policies\Concerns\ChecksSystemPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function grantSystemPermission(User $user, SystemPermission $permission): void
{
    Permission::findOrCreate($permission->value, 'web');
    $user->givePermissionTo($permission->value);
}

it('lets a holder of the view permission read the limits without changing them', function (): void {
    // Not an admin: the factory default is, and its bypass would answer these
    // questions instead of the permission under test.
    $user = User::factory()->employee()->create();
    grantSystemPermission($user, SystemPermission::ConstraintView);

    $policy = new BusinessConstraintPolicy;

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->view($user))->toBeTrue()
        ->and($policy->update($user))->toBeFalse()
        ->and($policy->create($user))->toBeFalse();
});

it('lets a holder of the manage permission move a limit', function (): void {
    $user = User::factory()->employee()->create();
    grantSystemPermission($user, SystemPermission::ConstraintManage);

    $policy = new BusinessConstraintPolicy;

    expect($policy->update($user))->toBeTrue()
        ->and($policy->create($user))->toBeTrue();
});

it('refuses a user holding neither permission', function (): void {
    $policy = new BusinessConstraintPolicy;
    $user = User::factory()->employee()->create();

    expect($policy->viewAny($user))->toBeFalse()
        ->and($policy->view($user))->toBeFalse()
        ->and($policy->create($user))->toBeFalse()
        ->and($policy->update($user))->toBeFalse();
});

it('never deletes a constraint, because resetting one is an audited act of its own', function (): void {
    $policy = new BusinessConstraintPolicy;

    expect($policy->delete())->toBeFalse()
        ->and($policy->forceDelete())->toBeFalse();
});

it('keeps full bypass for an admin who holds no scoped dashboard role', function (): void {
    $policy = new BusinessConstraintPolicy;

    expect($policy->update(User::factory()->admin()->create()))->toBeTrue();
});

it('narrows an admin who has been given a scoped dashboard role', function (): void {
    // Mirrors every other module: the moment an admin is also a Sales Officer,
    // their access is what that role grants, not what being an admin implies.
    Role::findOrCreate(DashboardRole::SalesOfficer->value, 'web');

    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::SalesOfficer->value);

    expect((new BusinessConstraintPolicy)->update($user))->toBeFalse();
});

it('returns false for an ability the permission map does not name', function (): void {
    $policy = new class
    {
        use ChecksSystemPermissions;

        public function checks(User $user, string $ability): bool
        {
            return $this->authorizeSystemAbility($user, $ability);
        }

        /** @return array<string, string> */
        protected function systemPermissionMap(): array
        {
            return [];
        }
    };

    expect($policy->checks(User::factory()->admin()->create(), 'restore'))->toBeFalse();
});
