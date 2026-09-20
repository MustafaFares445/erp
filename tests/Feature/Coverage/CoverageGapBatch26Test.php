<?php

declare(strict_types=1);

use App\Data\Inventory\LogisticsInboundLineData;
use App\Enums\PurchaseInboundStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use App\Services\Inventory\LogisticsInboundProjectionService;
use App\Services\Logistics\OutboundAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inboundCoverageLine(
    string $received = '0.000000',
    string $inProgress = '0.000000',
    string $remaining = '1.000000',
    string $allocatable = '0.000000',
    string $available = '0.000000',
    string $backordered = '0.000000',
): LogisticsInboundLineData {
    return new LogisticsInboundLineData(
        purchaseOrderLineId: 1,
        purchaseInboundLineId: 1,
        sku: 'COV-SKU',
        product: 'Coverage product',
        uom: 'EA',
        orderedBaseQuantity: '1.000000',
        confirmedBaseQuantity: '1.000000',
        backorderedBaseQuantity: $backordered,
        allocatedBaseQuantity: '1.000000',
        receivedBaseQuantity: $received,
        receiptInProgressBaseQuantity: $inProgress,
        remainingBaseQuantity: $remaining,
        currentlyAllocatableBaseQuantity: $allocatable,
        availableToReceiveBaseQuantity: $available,
        allocations: [],
        blockers: [],
        nextAction: 'Coverage',
    );
}

it('covers inbound projection business states and line actions', function (): void {
    $service = (new ReflectionClass(LogisticsInboundProjectionService::class))->newInstanceWithoutConstructor();

    $businessState = new ReflectionMethod(LogisticsInboundProjectionService::class, 'businessState');
    $lineNextAction = new ReflectionMethod(LogisticsInboundProjectionService::class, 'lineNextAction');

    $inbound = new PurchaseInbound;
    $inbound->forceFill(['status' => PurchaseInboundStatus::AwaitingReceipt]);

    expect($businessState->invoke($service, $inbound, [
        inboundCoverageLine(remaining: '0.000000'),
    ]))->toBe('Received');

    expect($businessState->invoke($service, $inbound, [
        inboundCoverageLine(available: '1.000000'),
    ]))->toBe('Ready to Receive');

    expect($businessState->invoke($service, $inbound, [
        inboundCoverageLine(allocatable: '1.000000'),
    ]))->toBe('Awaiting Allocation');

    expect($lineNextAction->invoke(
        $service,
        ['currently_allocatable' => '1.000000'],
        '1.000000',
        '0.000000',
        '0.000000',
    ))->toBe('Allocate quantity');

    expect($lineNextAction->invoke(
        $service,
        ['currently_allocatable' => '0.000000'],
        '1.000000',
        '0.000000',
        '1.000000',
    ))->toBe('Complete open receipt');

    expect($lineNextAction->invoke(
        $service,
        ['currently_allocatable' => '0.000000'],
        '0.000000',
        '0.000000',
        '0.000000',
    ))->toBe('Completed');
});

it('covers inbound projection quantity-property and next-action mappings', function (): void {
    $service = (new ReflectionClass(LogisticsInboundProjectionService::class))->newInstanceWithoutConstructor();
    $line = inboundCoverageLine(
        received: '2.000000',
        remaining: '3.000000',
        allocatable: '4.000000',
        available: '5.000000',
        backordered: '6.000000',
    );

    $quantity = new ReflectionMethod(LogisticsInboundProjectionService::class, 'lineQuantity');

    expect($quantity->invoke($service, $line, 'backorderedBaseQuantity'))->toBe('6.000000')
        ->and($quantity->invoke($service, $line, 'availableToReceiveBaseQuantity'))->toBe('5.000000')
        ->and($quantity->invoke($service, $line, 'receivedBaseQuantity'))->toBe('2.000000')
        ->and($quantity->invoke($service, $line, 'currentlyAllocatableBaseQuantity'))->toBe('4.000000')
        ->and($quantity->invoke($service, $line, 'remainingBaseQuantity'))->toBe('3.000000');

    $nextAction = new ReflectionMethod(LogisticsInboundProjectionService::class, 'nextAction');

    expect($nextAction->invoke($service, 'Ready to Receive'))->toBe('Complete draft receipt')
        ->and($nextAction->invoke($service, 'Partially Received'))->toBe('Continue receiving')
        ->and($nextAction->invoke($service, 'Unknown'))->toBe('View details');
});

it('skips serial stock without available serial units', function (): void {
    $variant = ProductVariant::factory()->machine()->create();
    $warehouse = Warehouse::factory()->create();
    $order = Order::factory()->create();

    OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 1,
        'unit_id' => $variant->unit_id,
    ]);

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'available_quantity' => 1,
    ]);

    expect(app(OutboundAvailabilityService::class)->suggest($order))->toBe([]);
});

it('stops outbound stock scanning once demand is fully allocated', function (): void {
    $variant = ProductVariant::factory()->machine()->create();
    $warehouseA = Warehouse::factory()->create();
    $warehouseB = Warehouse::factory()->create();
    $order = Order::factory()->create();

    OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 1,
        'unit_id' => $variant->unit_id,
    ]);

    foreach ([$warehouseA, $warehouseB] as $warehouse) {
        InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
            'on_hand_quantity' => 2,
            'reserved_quantity' => 0,
            'available_quantity' => 2,
        ]);

        SerializedInventoryUnit::factory()->count(2)->for($variant, 'productVariant')->create([
            'warehouse_id' => $warehouse->getKey(),
            'status' => SerializedInventoryUnitStatus::Available,
        ]);
    }

    $suggestions = app(OutboundAvailabilityService::class)->suggest($order);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['warehouse_id'])->toBe($warehouseA->getKey());
});

it('ignores outbound stock quantities at the allocation tolerance', function (): void {
    $variant = ProductVariant::factory()->create([
        'track_serials' => false,
        'track_batches' => false,
    ]);
    $warehouse = Warehouse::factory()->create();
    $order = Order::factory()->create();

    OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 1,
        'unit_id' => $variant->unit_id,
    ]);

    InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'on_hand_quantity' => '0.000001',
        'reserved_quantity' => 0,
        'available_quantity' => '0.000001',
    ]);

    expect(app(OutboundAvailabilityService::class)->suggest($order))->toBe([]);
});

it('ignores batch lots whose allocatable quantity is at tolerance', function (): void {
    $variant = ProductVariant::factory()->grain()->create();
    $warehouse = Warehouse::factory()->create();

    $lot = InventoryLot::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'on_hand_quantity' => '0.000001',
            'reserved_quantity' => '0.000000',
        ]);

    InventoryLotBalance::query()
        ->where('inventory_lot_id', $lot->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->update([
            'on_hand_base_quantity' => '0.000001',
            'reserved_base_quantity' => '0.000000',
        ]);

    $method = new ReflectionMethod(OutboundAvailabilityService::class, 'trackedAssignments');
    $assignments = $method->invoke(
        app(OutboundAvailabilityService::class),
        $variant->refresh(),
        (int) $warehouse->getKey(),
        1.0,
    );

    expect($assignments)->toBe([]);
});

it('skips outbound demand whose product variant has been soft deleted', function (): void {
    $variant = ProductVariant::factory()->create([
        'track_serials' => false,
        'track_batches' => false,
    ]);
    $order = Order::factory()->create();

    OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 1,
        'unit_id' => $variant->unit_id,
    ]);

    $variant->delete();

    expect(app(OutboundAvailabilityService::class)->suggest($order))->toBe([]);
});
