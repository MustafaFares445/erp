<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\Concerns\ChecksEmployeePermissions;

it('denies an ability that has no entry in the permission map', function (): void {
    $policy = new class
    {
        use ChecksEmployeePermissions;

        public function someAbility(User $user): bool
        {
            return $this->authorizeEmployeeAbility($user, 'unmapped-ability');
        }

        /** @return array<string, string> */
        protected function employeePermissionMap(): array
        {
            return [];
        }
    };

    expect($policy->someAbility(User::factory()->make()))->toBeFalse();
});

it('never allows force-deleting through the shared trait', function (): void {
    $policy = new class
    {
        use ChecksEmployeePermissions;

        /** @return array<string, string> */
        protected function employeePermissionMap(): array
        {
            return [];
        }
    };

    expect($policy->forceDelete())->toBeFalse();
});
