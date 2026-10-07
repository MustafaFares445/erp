<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\OrderStatus;
use App\Enums\SalesPermission;
use App\Enums\ShipmentStatus;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\OutboundFulfillments\OutboundFulfillmentResource;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\SalesProcurementRequirement;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Sales\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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

function workQueueUser(array $permissions): User
{
    $role = Role::findOrCreate('order-queue-'.md5(implode(',', $permissions)), 'web');
    $role->givePermissionTo(array_map(
        static fn (string $name): Permission => Permission::findOrCreate($name, 'web'),
        $permissions,
    ));
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function workQueueDelivery(Order $order, string $state): InventoryOperation
{
    $line = OrderLine::factory()->for($order)->create(['quantity' => 2]);
    $delivery = InventoryOperation::factory()->delivery()->{$state}()->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
        'customer_id' => $order->customer_id,
    ]);
    $delivery->lines()->create([
        'product_variant_id' => $line->product_variant_id,
        'order_line_id' => $line->getKey(),
        'quantity' => 2,
        'unit_id' => $line->unit_id,
    ]);

    return $delivery->refresh();
}

it('offers confirm and release as the primary row action for draft and confirmed orders', function (): void {
    $user = workQueueUser([
        SalesPermission::OrderView->value,
        SalesPermission::OrderConfirm->value,
        SalesPermission::OrderRelease->value,
    ]);
    $draft = Order::factory()->create(['status' => OrderStatus::Draft]);
    $confirmed = Order::factory()->create(['status' => OrderStatus::Confirmed]);

    Livewire::actingAs($user)
        ->test(ListOrders::class)
        ->assertTableActionVisible('confirm_order', $draft)
        ->assertTableActionHidden('release_order', $draft)
        ->assertTableActionVisible('release_order', $confirmed)
        ->assertTableActionHidden('confirm_order', $confirmed)
        ->assertTableActionHidden('next_step', $draft)
        ->assertTableActionHidden('next_step', $confirmed);

    $service = new class
    {
        public int $confirmed = 0;

        public function confirm(User $actor, Order $order): void
        {
            $this->confirmed++;
        }
    };
    app()->instance(SalesOrderService::class, $service);

    Livewire::actingAs($user)
        ->test(ListOrders::class)
        ->callTableAction('confirm_order', $draft);

    expect($service->confirmed)->toBe(1);
});

it('hides confirm from a viewer who is not authorized to confirm', function (): void {
    $draft = Order::factory()->create(['status' => OrderStatus::Draft]);

    Livewire::actingAs(workQueueUser([SalesPermission::OrderView->value]))
        ->test(ListOrders::class)
        ->assertTableActionHidden('confirm_order', $draft);
});

it('links released orders to the right outbound step with an explicit label', function (): void {
    Gate::before(static fn (): bool => true);
    $toPlan = Order::factory()->create(['status' => OrderStatus::Released]);
    OrderLine::factory()->for($toPlan)->create(['quantity' => 3]);

    $toDispatch = Order::factory()->create(['status' => OrderStatus::Released]);
    workQueueDelivery($toDispatch, 'ready');

    $blocked = Order::factory()->create(['status' => OrderStatus::Released]);
    $line = OrderLine::factory()->for($blocked)->create(['quantity' => 3]);
    SalesProcurementRequirement::query()->create([
        'order_id' => $blocked->getKey(),
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $line->product_variant_id,
        'required_base_quantity' => 2,
        'fulfilled_base_quantity' => 0,
        'status' => 'open',
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(ListOrders::class)
        ->assertTableActionVisible('next_step', $toPlan)
        ->assertTableActionHasLabel('next_step', 'Allocate stock', $toPlan)
        ->assertTableActionHasUrl('next_step', OutboundFulfillmentResource::getUrl('view', ['record' => $toPlan]), $toPlan)
        ->assertTableActionHasLabel('next_step', 'Dispatch goods', $toDispatch)
        ->assertTableActionHasUrl('next_step', OutboundFulfillmentResource::getUrl('view', ['record' => $toDispatch]), $toDispatch)
        ->assertTableActionHasLabel('next_step', 'Resolve supply requirement', $blocked)
        ->assertTableActionHasUrl('next_step', PurchaseNeeds::getUrl(), $blocked);
});

it('shows the supply blocker and links an order viewer to outbound review when purchasing is unavailable', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Released]);
    $line = OrderLine::factory()->for($order)->create(['quantity' => 3]);
    SalesProcurementRequirement::query()->create([
        'order_id' => $order->id,
        'order_line_id' => $line->id,
        'product_variant_id' => $line->product_variant_id,
        'required_base_quantity' => 2,
        'fulfilled_base_quantity' => 0,
        'status' => 'open',
    ]);
    $viewer = workQueueUser([SalesPermission::OrderView->value, InventoryPermission::DeliveryView->value]);

    Livewire::actingAs($viewer)->test(ListOrders::class)
        ->assertTableActionHasLabel('next_step', 'Review supply requirement', $order)
        ->assertTableActionHasUrl('next_step', OutboundFulfillmentResource::getUrl('view', ['record' => $order]), $order);
    Livewire::actingAs($viewer)->test(ViewOrder::class, ['record' => $order->id])
        ->assertSee('Supply blocked')
        ->assertSee('Outstanding supply: 2 base units');
    expect(PurchaseNeeds::canAccess())->toBeFalse();
});

it('links an order with a completed uninvoiced delivery to that delivery note to create the invoice', function (): void {
    Gate::before(static fn (): bool => true);
    $order = Order::factory()->create(['status' => OrderStatus::Released]);
    $delivery = workQueueDelivery($order, 'done');
    Shipment::factory()->create([
        'order_id' => $order->getKey(),
        'inventory_operation_id' => $delivery->getKey(),
        'status' => ShipmentStatus::Arrived,
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(ListOrders::class)
        ->assertTableActionHasLabel('next_step', 'Create invoice', $order)
        ->assertTableActionHasUrl('next_step', DeliveryNoteResource::getUrl('view', ['record' => $delivery]), $order);
});

it('gives terminal orders no workflow action and hides logistics steps from unauthorized users', function (): void {
    $closed = Order::factory()->create(['status' => OrderStatus::Closed]);
    $cancelled = Order::factory()->create(['status' => OrderStatus::Cancelled]);
    $released = Order::factory()->create(['status' => OrderStatus::Released]);
    OrderLine::factory()->for($released)->create(['quantity' => 3]);

    Livewire::actingAs(workQueueUser([SalesPermission::OrderView->value]))
        ->test(ListOrders::class)
        ->assertTableActionHidden('next_step', $closed)
        ->assertTableActionHidden('next_step', $cancelled)
        ->assertTableActionHidden('next_step', $released)
        ->assertTableActionVisible('view', $closed);
});
