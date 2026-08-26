<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\User;
use App\Policies\Concerns\ChecksSalesPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

interface SalesPolicyStub
{
    public function checks(User $user, string $ability): bool;

    public function forceDelete(): bool;
}

/**
 * @param  array<string, string>  $map
 */
function makeSalesPolicy(array $map): SalesPolicyStub
{
    return new readonly class($map) implements SalesPolicyStub
    {
        use ChecksSalesPermissions;

        /**
         * @param  array<string, string>  $map
         */
        public function __construct(private array $map) {}

        /**
         * @return array<string, string>
         */
        protected function salesPermissionMap(): array
        {
            return $this->map;
        }

        public function checks(User $user, string $ability): bool
        {
            return $this->authorizeSalesAbility($user, $ability);
        }
    };
}

it('grants an ability when the user has its mapped permission', function (): void {
    Permission::create(['name' => 'sales.quotation.view', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->givePermissionTo('sales.quotation.view');

    $policy = makeSalesPolicy(['viewAny' => 'sales.quotation.view']);

    expect($policy->checks($user, 'viewAny'))->toBeTrue();
});

it('denies an ability when the user lacks its mapped permission', function (): void {
    Permission::create(['name' => 'sales.quotation.view', 'guard_name' => 'web']);

    // Not an admin — the factory defaults to Admin, which would bypass the map
    // entirely and defeat the point of this test.
    $user = User::factory()->employee()->create();

    $policy = makeSalesPolicy(['viewAny' => 'sales.quotation.view']);

    expect($policy->checks($user, 'viewAny'))->toBeFalse();
});

it('denies an ability that has no mapped permission', function (): void {
    $user = User::factory()->employee()->create();

    $policy = makeSalesPolicy([]);

    expect($policy->checks($user, 'delete'))->toBeFalse();
});

it('lets an admin holding no fixed dashboard role bypass every mapped ability', function (): void {
    $admin = User::factory()->admin()->create();

    $policy = makeSalesPolicy(['viewAny' => 'sales.quotation.view']);

    expect($policy->checks($admin, 'viewAny'))->toBeTrue();
});

it('narrows an admin who also holds a fixed dashboard role to an explicit check', function (): void {
    // Assigning any scoped role — even one from another module — is a
    // statement that this user's access is scoped, not a blanket bypass.
    Permission::create(['name' => 'sales.quotation.view', 'guard_name' => 'web']);
    $admin = User::factory()->admin()->create();
    Role::findOrCreate(DashboardRole::SalesOfficer->value, 'web');
    $admin->assignRole(DashboardRole::SalesOfficer->value);

    $policy = makeSalesPolicy(['viewAny' => 'sales.quotation.view']);

    expect($policy->checks($admin, 'viewAny'))->toBeFalse();

    $admin->givePermissionTo('sales.quotation.view');

    expect($policy->checks($admin->refresh(), 'viewAny'))->toBeTrue();
});

it('refuses forceDelete unconditionally — no role hard-deletes a sales record', function (): void {
    $policy = makeSalesPolicy([]);

    expect($policy->forceDelete())->toBeFalse();
});
