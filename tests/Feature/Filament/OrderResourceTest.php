<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('renders the order creation wizard for a delivery creator', function (): void {
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $createPermission = Permission::findOrCreate(InventoryPermission::DeliveryCreate->value, 'web');
    $role = Role::findOrCreate('order-wizard-creator', 'web');
    $role->givePermissionTo([$viewPermission, $createPermission]);

    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(OrderResource::getUrl('create'))
        ->assertOk()
        ->assertSee('Select customer')
        ->assertSee('Select products')
        ->assertSee('Select warehouses')
        ->assertSee('Delivery routes preview');
});

it('denies the order list and creation pages without the delivery view permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(OrderResource::getUrl('index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(OrderResource::getUrl('create'))
        ->assertForbidden();
});

it('lists orders with their customer, delivery count, status, and created date', function (): void {
    $viewPermission = Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web');
    $role = Role::findOrCreate('order-list-viewer', 'web');
    $role->givePermissionTo($viewPermission);

    $user = User::factory()->create();
    $user->assignRole($role);

    $order = Order::factory()->create(['order_number' => 'SO-000042', 'status' => 'ready']);

    $this->actingAs($user)
        ->get(OrderResource::getUrl('index'))
        ->assertOk()
        ->assertSee('SO-000042')
        ->assertSee($order->customer->company_name)
        ->assertSee('Create');
});
