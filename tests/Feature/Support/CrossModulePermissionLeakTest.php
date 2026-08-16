<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\CustomerProfilePolicy;
use App\Policies\EmployeeProfilePolicy;
use App\Policies\WarehousePolicy;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\EmployeePermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new CrmPermissionSeeder)->run();
    (new EmployeePermissionSeeder)->run();
    (new SupportPermissionSeeder)->run();
});

it('denies an admin whose only role is Support Manager from managing Employees, CRM, or Inventory records', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Support Manager');

    expect(app(EmployeeProfilePolicy::class)->viewAny($user))->toBeFalse()
        ->and(app(EmployeeProfilePolicy::class)->create($user))->toBeFalse()
        ->and(app(CustomerProfilePolicy::class)->viewAny($user))->toBeFalse()
        ->and(app(CustomerProfilePolicy::class)->create($user))->toBeFalse()
        ->and(app(WarehousePolicy::class)->viewAny($user))->toBeFalse()
        ->and(app(WarehousePolicy::class)->create($user))->toBeFalse();
});

it('denies an admin whose only role is Support Agent from managing Employees, CRM, or Inventory records', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Support Agent');

    expect(app(EmployeeProfilePolicy::class)->viewAny($user))->toBeFalse()
        ->and(app(EmployeeProfilePolicy::class)->create($user))->toBeFalse()
        ->and(app(CustomerProfilePolicy::class)->viewAny($user))->toBeFalse()
        ->and(app(CustomerProfilePolicy::class)->create($user))->toBeFalse()
        ->and(app(WarehousePolicy::class)->viewAny($user))->toBeFalse()
        ->and(app(WarehousePolicy::class)->create($user))->toBeFalse();
});
