<?php

declare(strict_types=1);

use App\Enums\ShipmentStatus;
use App\Filament\Resources\OutboundFulfillments\Pages\ViewOutboundFulfillment;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Logistics\OutboundAvailabilityService;
use App\Services\Logistics\OutboundDispatchService;
use App\Services\Logistics\OutboundFulfillmentService;
use App\Services\Sales\SalesProcurementRequirementService;
use App\Services\Shipments\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('executes outbound fulfillment page action callbacks', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $order = Order::factory()->create();
    $draftDelivery = InventoryOperation::factory()->delivery()->draft()->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
        'customer_id' => $order->customer_id,
    ]);
    $readyDelivery = InventoryOperation::factory()->delivery()->ready()->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
        'customer_id' => $order->customer_id,
    ]);
    $shipment = Shipment::factory()->create([
        'order_id' => $order->getKey(),
        'inventory_operation_id' => $readyDelivery->getKey(),
        'status' => ShipmentStatus::InTransit,
    ]);

    $procurement = new class
    {
        public int $calls = 0;

        public function synchronize(Order $order, User $actor): void
        {
            $this->calls++;
        }
    };
    app()->instance(SalesProcurementRequirementService::class, $procurement);

    $availability = new class
    {
        /** @var list<array<string,mixed>> */
        public array $result = [];

        /** @return list<array<string,mixed>> */
        public function suggest(Order $order): array
        {
            return $this->result;
        }
    };
    app()->instance(OutboundAvailabilityService::class, $availability);

    $fulfillment = new class
    {
        public int $plans = 0;

        public int $prepares = 0;

        /** @param list<array<string,mixed>> $shipments */
        public function plan(User $actor, Order $order, array $shipments): void
        {
            $this->plans++;
        }

        public function prepare(User $actor, InventoryOperation $delivery): void
        {
            $this->prepares++;
        }
    };
    app()->instance(OutboundFulfillmentService::class, $fulfillment);

    $dispatch = new class
    {
        public int $calls = 0;

        public function dispatch(User $actor, InventoryOperation $delivery): void
        {
            $this->calls++;
        }
    };
    app()->instance(OutboundDispatchService::class, $dispatch);

    $shipmentService = new class
    {
        public int $calls = 0;

        public function confirmByAdmin(Shipment $shipment, User $actor): void
        {
            $this->calls++;
        }
    };
    app()->instance(ShipmentService::class, $shipmentService);

    $page = app(ViewOutboundFulfillment::class);
    $actions = collect($page->getHeaderActions())->keyBy(fn ($action): string => $action->getName());

    $actions['refreshAvailability']->getActionFunction()($order);
    expect($procurement->calls)->toBe(1);

    $actions['createDeliveryPlan']->getActionFunction()($order);
    expect($procurement->calls)->toBe(2)
        ->and($fulfillment->plans)->toBe(0);

    $availability->result = [[
        'warehouse_id' => 1,
        'assignments' => [['product_variant_id' => 1, 'quantity' => 1]],
    ]];
    $actions['createDeliveryPlan']->getActionFunction()($order);
    expect($fulfillment->plans)->toBe(1)
        ->and($procurement->calls)->toBe(3);

    $actions['prepareDelivery']->getActionFunction()($order, [
        'delivery_id' => $draftDelivery->getKey(),
    ]);
    expect($fulfillment->prepares)->toBe(1);

    $actions['dispatchGoods']->getActionFunction()($order, [
        'delivery_id' => $readyDelivery->getKey(),
    ]);
    expect($dispatch->calls)->toBe(1);

    $actions['confirmArrival']->getActionFunction()($order, [
        'shipment_id' => $shipment->getKey(),
    ]);
    expect($shipmentService->calls)->toBe(1);
});

it('covers outbound page actor input and arrival authorization guards', function (): void {
    $actor = User::factory()->create();
    $order = Order::factory()->create();
    $shipment = Shipment::factory()->create([
        'order_id' => $order->getKey(),
        'status' => ShipmentStatus::InTransit,
    ]);

    $page = app(ViewOutboundFulfillment::class);

    $this->actingAs($actor);
    $actorMethod = new ReflectionMethod(ViewOutboundFulfillment::class, 'actor');
    $inputMethod = new ReflectionMethod(ViewOutboundFulfillment::class, 'integerInput');

    expect($actorMethod->invoke($page)->is($actor))->toBeTrue()
        ->and($inputMethod->invoke($page, '42'))->toBe(42)
        ->and(fn (): mixed => $inputMethod->invoke($page, 'not-numeric'))
        ->toThrow(LogicException::class, 'numeric record identifier');

    $actions = collect($page->getHeaderActions())->keyBy(fn ($action): string => $action->getName());

    expect(fn () => $actions['confirmArrival']->getActionFunction()($order, [
        'shipment_id' => $shipment->getKey(),
    ]))->toThrow(LogicException::class, 'not authorized');

    auth()->logout();
    expect(fn (): mixed => $actorMethod->invoke($page))
        ->toThrow(LogicException::class, 'authenticated Logistics user');
});
