<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

interface InventoryPolicyStub
{
    public function checks(User $user, string $ability): bool;
}

/**
 * @param  array<string, string>  $map
 */
function makeInventoryPolicy(array $map): InventoryPolicyStub
{
    return new readonly class($map) implements InventoryPolicyStub
    {
        use ChecksInventoryPermissions;

        /**
         * @param  array<string, string>  $map
         */
        public function __construct(private array $map) {}

        /**
         * @return array<string, string>
         */
        protected function inventoryPermissionMap(): array
        {
            return $this->map;
        }

        public function checks(User $user, string $ability): bool
        {
            return $this->authorizeInventoryAbility($user, $ability);
        }
    };
}

it('grants an ability when the user has its mapped permission', function (): void {
    Permission::create(['name' => 'inventory.warehouse.view', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->givePermissionTo('inventory.warehouse.view');

    $policy = makeInventoryPolicy(['viewAny' => 'inventory.warehouse.view']);

    expect($policy->checks($user, 'viewAny'))->toBeTrue();
});

it('denies an ability when the user lacks its mapped permission', function (): void {
    Permission::create(['name' => 'inventory.warehouse.view', 'guard_name' => 'web']);

    $user = User::factory()->create();

    $policy = makeInventoryPolicy(['viewAny' => 'inventory.warehouse.view']);

    expect($policy->checks($user, 'viewAny'))->toBeFalse();
});

it('denies an ability that has no mapped permission, such as delete on ledgers', function (): void {
    $user = User::factory()->create();

    $policy = makeInventoryPolicy([]);

    expect($policy->checks($user, 'delete'))->toBeFalse();
});
