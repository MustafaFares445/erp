<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\AdminModuleRegistry;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\EmployeePermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach ([
        InventoryPermissionSeeder::class,
        CrmPermissionSeeder::class,
        EmployeePermissionSeeder::class,
        SupportPermissionSeeder::class,
        AccountingPermissionSeeder::class,
        PurchasePermissionSeeder::class,
        SalesPermissionSeeder::class,
    ] as $seeder) {
        (new $seeder)->run();
    }
});

function moduleSwitcherUser(DashboardRole $role): User
{
    $user = User::factory()->admin()->create();
    $user->syncRoles([$role->value]);

    return $user;
}

/** @return list<string> */
function accessibleGroupKeys(): array
{
    return array_map(
        static fn (array $group): string => $group['key'],
        AdminModuleRegistry::accessibleGroups(),
    );
}

it('shows every module group to a system administrator', function (): void {
    Auth::login(moduleSwitcherUser(DashboardRole::SystemAdmin));

    $allKeys = array_map(
        static fn (array $group): string => $group['key'],
        AdminModuleRegistry::groups(),
    );

    expect(accessibleGroupKeys())->toBe($allKeys);
});

it('hides module groups a scoped role cannot open, so no tab is a dead end', function (): void {
    // A support agent could previously see all nine tabs; seven of them had no
    // reachable item, so firstUrlFor() fell back to the panel home and the
    // click silently bounced them back to the dashboard they started on.
    Auth::login(moduleSwitcherUser(DashboardRole::SupportAgent));

    $keys = accessibleGroupKeys();

    expect($keys)->toContain('support')
        ->and($keys)->not->toContain('sales')
        ->and($keys)->not->toContain('accounting')
        ->and($keys)->not->toContain('inventory')
        ->and($keys)->not->toContain('vendors')
        ->and($keys)->not->toContain('crm')
        ->and($keys)->not->toContain('employees');
});

it('never offers a group whose landing page would bounce back to the panel home', function (): void {
    foreach ([DashboardRole::SupportAgent, DashboardRole::PayrollOfficer, DashboardRole::PurchasingManager] as $role) {
        Auth::login(moduleSwitcherUser($role));

        foreach (AdminModuleRegistry::accessibleGroups() as $group) {
            expect(AdminModuleRegistry::firstUrlFor($group))
                ->not->toBe(url('/admin'), "group {$group['key']} bounces for {$role->value}");
        }

        Auth::logout();
    }
});
