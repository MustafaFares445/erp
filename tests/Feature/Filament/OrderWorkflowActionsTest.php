<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\SalesPermission;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('short-closes remaining released demand from the order view', function (): void {
    $role = Role::findOrCreate('order-short-closer', 'web');
    $role->givePermissionTo([
        Permission::findOrCreate(SalesPermission::OrderView->value, 'web'),
        Permission::findOrCreate(SalesPermission::OrderClose->value, 'web'),
    ]);
    $user = User::factory()->admin()->create();
    $user->assignRole($role);

    $order = Order::factory()->create(['status' => OrderStatus::Released]);
    $line = OrderLine::factory()->for($order)->create(['quantity' => 3]);

    Livewire::actingAs($user)
        ->test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionVisible('short_close_order')
        ->callAction('short_close_order', data: [
            'lines' => [[
                'order_line_id' => $line->getKey(),
                'quantity' => 2,
            ]],
            'reason' => 'Customer reduced the requested quantity.',
        ])
        ->assertHasNoActionErrors();

    expect((float) $line->fresh()->short_closed_base_quantity)->toBe(2.0);
});
