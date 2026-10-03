<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\OutboundFulfillments\Pages\ListOutboundFulfillments;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\SalesProcurementRequirement;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Logistics\OutboundDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function outboundQueueOrder(): Order
{
    return Order::factory()->create(['status' => OrderStatus::Released]);
}

function outboundQueueDelivery(Order $order, string $state): InventoryOperation
{
    return InventoryOperation::factory()->delivery()->{$state}()->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
        'customer_id' => $order->customer_id,
    ]);
}

function outboundQueueViewer(): User
{
    $role = Role::findOrCreate('outbound-queue-viewer', 'web');
    $role->givePermissionTo(Permission::findOrCreate(InventoryPermission::DeliveryView->value, 'web'));
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('offers Create delivery plan for released demand that is not planned yet', function (): void {
    Gate::before(static fn (): bool => true);
    $order = outboundQueueOrder();
    OrderLine::factory()->for($order)->create(['quantity' => 3]);

    Livewire::actingAs(User::factory()->create())
        ->test(ListOutboundFulfillments::class)
        ->assertTableActionVisible('createDeliveryPlan', $order)
        ->assertTableActionHidden('prepareDelivery', $order)
        ->assertTableActionHidden('dispatchGoods', $order)
        ->assertTableActionHidden('confirmArrival', $order)
        ->assertTableActionHidden('reviewSupply', $order)
        ->assertTableActionHasLabel('createDeliveryPlan', 'Create delivery plan', $order);
});

it('links blocked supply to the purchasing needs page', function (): void {
    Gate::before(static fn (): bool => true);
    $order = outboundQueueOrder();
    $line = OrderLine::factory()->for($order)->create(['quantity' => 3]);
    SalesProcurementRequirement::query()->create([
        'order_id' => $order->getKey(),
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $line->product_variant_id,
        'required_base_quantity' => 2,
        'fulfilled_base_quantity' => 0,
        'status' => 'open',
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(ListOutboundFulfillments::class)
        ->assertTableActionVisible('reviewSupply', $order)
        ->assertTableActionHasLabel('reviewSupply', 'Review supply requirement', $order)
        ->assertTableActionHasUrl('reviewSupply', PurchaseNeeds::getUrl(), $order)
        ->assertTableActionHidden('createDeliveryPlan', $order);
});

it('offers Prepare delivery for a draft delivery and Dispatch goods for a ready one', function (): void {
    Gate::before(static fn (): bool => true);
    $draftOrder = outboundQueueOrder();
    outboundQueueDelivery($draftOrder, 'draft');
    $readyOrder = outboundQueueOrder();
    outboundQueueDelivery($readyOrder, 'ready');

    Livewire::actingAs(User::factory()->create())
        ->test(ListOutboundFulfillments::class)
        ->assertTableActionVisible('prepareDelivery', $draftOrder)
        ->assertTableActionHidden('dispatchGoods', $draftOrder)
        ->assertTableActionVisible('dispatchGoods', $readyOrder)
        ->assertTableActionHidden('prepareDelivery', $readyOrder);
});

it('dispatches a ready delivery from the row through the dispatch service exactly once', function (): void {
    Gate::before(static fn (): bool => true);
    $order = outboundQueueOrder();
    $delivery = outboundQueueDelivery($order, 'ready');

    $dispatch = new class
    {
        public int $calls = 0;

        public ?int $deliveryId = null;

        public function dispatch(User $actor, InventoryOperation $delivery): void
        {
            $this->calls++;
            $this->deliveryId = $delivery->getKey();
        }
    };
    app()->instance(OutboundDispatchService::class, $dispatch);

    Livewire::actingAs(User::factory()->create())
        ->test(ListOutboundFulfillments::class)
        ->callTableAction('dispatchGoods', $order, data: ['delivery_id' => $delivery->getKey()])
        ->assertHasNoTableActionErrors();

    expect($dispatch->calls)->toBe(1)->and($dispatch->deliveryId)->toBe($delivery->getKey());
});

it('offers Confirm arrival for an in-transit shipment', function (): void {
    Gate::before(static fn (): bool => true);
    $order = outboundQueueOrder();
    $delivery = outboundQueueDelivery($order, 'done');
    Shipment::factory()->create([
        'order_id' => $order->getKey(),
        'inventory_operation_id' => $delivery->getKey(),
        'status' => ShipmentStatus::InTransit,
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(ListOutboundFulfillments::class)
        ->assertTableActionVisible('confirmArrival', $order)
        ->assertTableActionHasLabel('confirmArrival', 'Confirm arrival', $order);
});

it('shows no workflow action for an order with nothing left to do', function (): void {
    Gate::before(static fn (): bool => true);
    $order = outboundQueueOrder();
    $delivery = outboundQueueDelivery($order, 'done');
    Shipment::factory()->create([
        'order_id' => $order->getKey(),
        'inventory_operation_id' => $delivery->getKey(),
        'status' => ShipmentStatus::Arrived,
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(ListOutboundFulfillments::class)
        ->assertTableActionVisible('view', $order)
        ->assertTableActionHidden('createDeliveryPlan', $order)
        ->assertTableActionHidden('prepareDelivery', $order)
        ->assertTableActionHidden('dispatchGoods', $order)
        ->assertTableActionHidden('confirmArrival', $order)
        ->assertTableActionHidden('reviewSupply', $order);
});

it('hides workflow actions from a user who may only view the queue', function (): void {
    $planOrder = outboundQueueOrder();
    OrderLine::factory()->for($planOrder)->create(['quantity' => 3]);
    $readyOrder = outboundQueueOrder();
    outboundQueueDelivery($readyOrder, 'ready');

    Livewire::actingAs(outboundQueueViewer())
        ->test(ListOutboundFulfillments::class)
        ->assertTableActionHidden('createDeliveryPlan', $planOrder)
        ->assertTableActionHidden('dispatchGoods', $readyOrder)
        ->assertTableActionVisible('view', $readyOrder);
});
