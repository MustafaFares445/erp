<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\WarehouseReplenishmentPolicies\Pages\ManageWarehouseReplenishmentPolicies;
use App\Models\User;
use App\Models\WarehouseReplenishmentPolicy;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

/**
 * NOTE: WarehouseReplenishmentPolicyResource is not yet registered in
 * AdminPanelServiceProvider's ->resources([...]) list, so it has no
 * generated Filament route and Resource::getUrl() cannot be resolved from a
 * test. Mounting its Livewire page component directly does not depend on
 * that panel registration, so it is used here instead of an HTTP request
 * against the (currently unreachable) resource URL.
 */
it('loads the replenishment policy management page for an authorized viewer', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::StockView->value);
    $policy = WarehouseReplenishmentPolicy::factory()->create();

    Livewire::actingAs($viewer)
        ->test(ManageWarehouseReplenishmentPolicies::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$policy]);
});

it('denies the replenishment policy management page without the stock view permission', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ManageWarehouseReplenishmentPolicies::class)
        ->assertForbidden();
});
