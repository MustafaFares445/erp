<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('seeds an explicit system administrator and the inventory permission catalogue', function (): void {
    $this->seed();

    $admin = User::query()->where('email', 'test@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->user_type)->toBe(UserType::Admin)
        ->and(Permission::query()->where('guard_name', 'web')->pluck('name')->all())
        ->toEqualCanonicalizing(InventoryPermission::values());
});
