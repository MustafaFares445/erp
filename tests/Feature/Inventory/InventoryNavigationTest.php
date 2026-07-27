<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * A user with every `inventory.*` permission, so every current Inventory
 * navigation item resolves to a real link and none are hidden by policy —
 * see App\Policies\Concerns\ChecksInventoryPermissions.
 */
function actingAsFullInventoryUser(): User
{
    (new InventoryPermissionSeeder)->run();

    $role = Role::firstOrCreate(['name' => 'inventory-full-access', 'guard_name' => 'web']);
    $role->syncPermissions(InventoryPermission::values());

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('renders the inventory sidebar as one named NavigationGroup per declared section', function (): void {
    app()->setLocale('en');
    $user = actingAsFullInventoryUser();

    $this->actingAs($user)->get(WarehouseResource::getUrl())->assertOk();

    expect(AdminModuleRegistry::activeGroupKey())->toBe('inventory');

    $inventoryGroup = collect(AdminModuleRegistry::groups())->firstWhere('key', 'inventory');

    $renderedGroups = collect(Filament::getPanel('admin')->buildNavigation());

    $namedGroups = $renderedGroups->filter(fn (NavigationGroup $group): bool => filled($group->getLabel()));

    expect($namedGroups)->toHaveCount(count($inventoryGroup['sections']));

    foreach ($inventoryGroup['sections'] as $section) {
        $expectedCount = collect($inventoryGroup['items'])
            ->where('section', $section['key'])
            ->count();

        $renderedGroup = $namedGroups->first(
            fn (NavigationGroup $group): bool => $group->getLabel() === __($section['label'], [], 'en'),
        );

        expect($renderedGroup)->not->toBeNull()
            ->and($renderedGroup->getItems())->toHaveCount($expectedCount);
    }
});

it('does not lose any inventory navigation item when scoping the sidebar into sections', function (): void {
    $user = actingAsFullInventoryUser();

    $this->actingAs($user)->get(WarehouseResource::getUrl());

    expect(AdminModuleRegistry::activeGroupKey())->toBe('inventory');

    $inventoryGroup = collect(AdminModuleRegistry::groups())->firstWhere('key', 'inventory');

    $navigationItems = collect(Filament::getPanel('admin')->buildNavigation())
        ->flatMap(fn (NavigationGroup $group) => $group->getItems());

    expect($navigationItems)->toHaveCount(1 + count($inventoryGroup['items']));
});

it('shows the section labels in the rendered sidebar HTML', function (): void {
    app()->setLocale('en');
    $user = actingAsFullInventoryUser();

    $response = $this->actingAs($user)->get(WarehouseResource::getUrl());

    $response->assertOk();
    $response->assertSee(__('admin.sections.catalog', [], 'en'));
    $response->assertSee(__('admin.sections.stock', [], 'en'));
    $response->assertSee(__('admin.sections.operations', [], 'en'));
    $response->assertSee(__('admin.sections.insights', [], 'en'));
});

it('leaves a module with no declared sections rendering as a single flat group', function (): void {
    $user = actingAsFullInventoryUser();

    $this->actingAs($user)->get(SupplierResource::getUrl());

    expect(AdminModuleRegistry::activeGroupKey())->toBe('purchasing');

    $renderedGroups = collect(Filament::getPanel('admin')->buildNavigation());

    $namedGroups = $renderedGroups->filter(fn (NavigationGroup $group): bool => filled($group->getLabel()));

    expect($namedGroups)->toBeEmpty();
});
