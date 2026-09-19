<?php

declare(strict_types=1);

use App\Enums\OperationStage;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\ShipmentStatus;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Logistics\OutboundAvailabilityService;
use App\Services\Logistics\OutboundDispatchService;
use App\Services\Logistics\OutboundFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function createOutboundCoverageDemand(float $quantity = 5.0): array
{
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $line = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => $quantity,
        'unit_id' => $variant->unit_id,
    ]);

    return [$order, $variant, $line];
}

it('suggests active available warehouse stock for untracked released demand', function (): void {
    [$order, $variant] = createOutboundCoverageDemand(5);
    $warehouse = Warehouse::factory()->create();
    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'on_hand_quantity' => 3,
        'reserved_quantity' => 0,
        'available_quantity' => 3,
    ]);
    SerializedInventoryUnit::factory()->count(3)->for($variant, 'productVariant')->create([
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    $suggestions = app(OutboundAvailabilityService::class)->suggest($order);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['warehouse_id'])->toBe($warehouse->getKey())
        ->and($suggestions[0]['assignments'])->toHaveCount(1)
        ->and($suggestions[0]['assignments'][0]['product_variant_id'])->toBe($variant->getKey())
        ->and($suggestions[0]['assignments'][0]['quantity'])->toBe(3.0);
});

it('returns no availability suggestions when the order has no remaining demand', function (): void {
    $order = Order::factory()->create();

    expect(app(OutboundAvailabilityService::class)->suggest($order))->toBe([]);
});

it('plans prepares and dispatches a valid outbound shipment end to end', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    [$order, $variant] = createOutboundCoverageDemand(4);
    $warehouse = Warehouse::factory()->create();
    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'on_hand_quantity' => 4,
        'reserved_quantity' => 0,
        'available_quantity' => 4,
    ]);
    SerializedInventoryUnit::factory()->count(4)->for($variant, 'productVariant')->create([
        'warehouse_id' => $warehouse->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    $availability = app(OutboundAvailabilityService::class)->suggest($order);
    $planned = app(OutboundFulfillmentService::class)->plan($actor, $order, $availability);
    $delivery = $planned->deliveries->first();

    expect($delivery)->toBeInstanceOf(InventoryOperation::class)
        ->and($delivery->stage)->toBe(OperationStage::Draft)
        ->and($planned->shipments)->toHaveCount(1)
        ->and($planned->shipments->first()->status)->toBe(ShipmentStatus::Planned);

    $prepared = app(OutboundFulfillmentService::class)->prepare($actor, $delivery);
    expect($prepared->stage)->toBe(OperationStage::Ready);

    $shipment = app(OutboundDispatchService::class)->dispatch($actor, $prepared, [
        'tracking_number' => 'TRK-COVERAGE',
    ]);

    expect($shipment->status)->toBe(ShipmentStatus::InTransit)
        ->and($shipment->tracking_number)->toBe('TRK-COVERAGE')
        ->and($prepared->refresh()->stage)->toBe(OperationStage::Done);
});

it('covers fulfillment planning validation guards', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $service = app(OutboundFulfillmentService::class);

    $emptyOrder = Order::factory()->create();
    expect(fn () => $service->plan($actor, $emptyOrder, []))
        ->toThrow(DomainException::class, 'The released order has no commercial demand.');

    [$order, $variant] = createOutboundCoverageDemand(2);
    expect(fn () => $service->plan($actor, $order, []))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->plan($actor, $order, [['assignments' => []]]))
        ->toThrow(ValidationException::class);

    $warehouse = Warehouse::factory()->create();
    $assignment = [
        'product_variant_id' => $variant->getKey(),
        'quantity' => 1,
        'inventory_lot_id' => null,
        'serialized_inventory_unit_ids' => [],
    ];

    expect(fn () => $service->plan($actor, $order, [
        ['warehouse_id' => $warehouse->getKey(), 'assignments' => [$assignment]],
        ['warehouse_id' => $warehouse->getKey(), 'assignments' => [$assignment]],
    ]))->toThrow(ValidationException::class);

    expect(fn () => $service->plan($actor, $order, [[
        'warehouse_id' => $warehouse->getKey(),
        'assignments' => [[...$assignment, 'quantity' => 3]],
    ]]))->toThrow(ValidationException::class);

    expect(fn () => $service->plan($actor, $order, [[
        'warehouse_id' => $warehouse->getKey(),
        'assignments' => [$assignment],
    ]]))->toThrow(ValidationException::class);
});

it('covers outbound dispatch type and planned-shipment guards', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $service = app(OutboundDispatchService::class);

    $receipt = InventoryOperation::factory()->receipt()->ready()->create();
    expect(fn () => $service->dispatch($actor, $receipt))
        ->toThrow(DomainException::class, 'Outbound dispatch is available only for customer Delivery operations.');

    $delivery = InventoryOperation::factory()->delivery()->ready()->create();
    expect(fn () => $service->dispatch($actor, $delivery))
        ->toThrow(DomainException::class, 'A planned shipment is required before dispatch.');

    Shipment::factory()->create([
        'inventory_operation_id' => $delivery->getKey(),
        'warehouse_id' => $delivery->source_warehouse_id,
        'status' => ShipmentStatus::Arrived,
    ]);

    expect(fn () => $service->dispatch($actor, $delivery))
        ->toThrow(DomainException::class, 'A planned shipment is required before dispatch.');
});
