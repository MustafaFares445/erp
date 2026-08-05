<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\CustomerProfilePolicy;
use App\Policies\PricingTierPolicy;
use App\Policies\WarehousePolicy;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new CrmPermissionSeeder)->run();
    (new EmployeePermissionSeeder)->run();
});

it('denies an admin whose only role is Payroll Officer from managing CRM customers or pricing tiers', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Payroll Officer');

    expect(app(CustomerProfilePolicy::class)->viewAny($user))->toBeFalse()
        ->and(app(CustomerProfilePolicy::class)->create($user))->toBeFalse()
        ->and(app(PricingTierPolicy::class)->viewAny($user))->toBeFalse()
        ->and(app(PricingTierPolicy::class)->create($user))->toBeFalse();
});

it('denies an admin whose only role is Employee Manager from managing CRM customers or Inventory records', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Employee Manager');

    expect(app(CustomerProfilePolicy::class)->viewAny($user))->toBeFalse()
        ->and(app(CustomerProfilePolicy::class)->create($user))->toBeFalse()
        ->and(app(WarehousePolicy::class)->viewAny($user))->toBeFalse()
        ->and(app(WarehousePolicy::class)->create($user))->toBeFalse()
        ->and(app(PricingTierPolicy::class)->viewAny($user))->toBeFalse();
});
