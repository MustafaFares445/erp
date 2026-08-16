<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

it('denies an ability that has no entry in the permission map', function (): void {
    $policy = new class
    {
        use ChecksSupportPermissions;

        public function someAbility(User $user): bool
        {
            return $this->authorizeSupportAbility($user, 'unmapped-ability');
        }

        /** @return array<string, string> */
        protected function supportPermissionMap(): array
        {
            return [];
        }
    };

    expect($policy->someAbility(User::factory()->make()))->toBeFalse();
});

it('never allows force-deleting through the shared trait', function (): void {
    $policy = new class
    {
        use ChecksSupportPermissions;

        /** @return array<string, string> */
        protected function supportPermissionMap(): array
        {
            return [];
        }
    };

    expect($policy->forceDelete())->toBeFalse();
});
