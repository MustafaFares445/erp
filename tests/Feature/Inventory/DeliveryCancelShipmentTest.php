<?php

declare(strict_types=1);

use App\Enums\ShipmentStatus;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Sales\OrderCompletionEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function plannedShipmentFor(InventoryOperation $delivery, ShipmentStatus $status = ShipmentStatus::Planned): Shipment
{
    $order = Order::factory()->create();
    $delivery->forceFill([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
    ])->save();

    return Shipment::factory()->create([
        'order_id' => $order->getKey(),
        'inventory_operation_id' => $delivery->getKey(),
        'warehouse_id' => $delivery->source_warehouse_id,
        'status' => $status,
    ]);
}

it('cancels the planned shipment of a draft delivery so the order is no longer blocked by it', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->create();
    $shipment = plannedShipmentFor($delivery);
    $order = $shipment->order;

    $blocked = app(OrderCompletionEligibilityService::class)->evaluate($order);
    expect($blocked->allShipmentsArrived)->toBeFalse();

    app(InventoryOperationService::class)->cancel($delivery, User::factory()->create(), 'customer changed mind');

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Cancelled)
        ->and(app(OrderCompletionEligibilityService::class)->evaluate($order->fresh())->allShipmentsArrived)->toBeTrue();
});

it('cancels the planned shipment of a ready delivery', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->ready()->create();
    $shipment = plannedShipmentFor($delivery);

    app(InventoryOperationService::class)->cancel($delivery, User::factory()->create(), 'out of stock');

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Cancelled);
});

it('never touches a shipment that already left the warehouse', function (ShipmentStatus $status): void {
    $delivery = InventoryOperation::factory()->delivery()->ready()->create();
    $shipment = plannedShipmentFor($delivery, $status);

    app(InventoryOperationService::class)->cancel($delivery, User::factory()->create(), 'mismatch');

    expect($shipment->fresh()->status)->toBe($status);
})->with([ShipmentStatus::InTransit, ShipmentStatus::Arrived]);

it('cancels a delivery that has no shipment and leaves other operation types alone', function (): void {
    $actor = User::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->create();
    $receipt = InventoryOperation::factory()->receipt()->create();
    $unrelated = Shipment::factory()->create();

    app(InventoryOperationService::class)->cancel($delivery, $actor, 'no shipment');
    app(InventoryOperationService::class)->cancel($receipt, $actor, 'receipt');

    expect($delivery->fresh()->isCanceled())->toBeTrue()
        ->and($receipt->fresh()->isCanceled())->toBeTrue()
        ->and($unrelated->fresh()->status)->toBe(ShipmentStatus::InTransit);
});

it('refuses to cancel a shipment that is not planned', function (): void {
    $shipment = Shipment::factory()->create(['status' => ShipmentStatus::InTransit]);

    expect(fn () => $shipment->markCancelled())
        ->toThrow(DomainException::class, 'Only a planned shipment may be cancelled.');
});
