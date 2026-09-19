<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\Exceptions\InvalidPurchaseInboundAllocation;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotAllocated;
use App\Services\Purchasing\PurchaseInboundService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function inboundDeepActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(InventoryPermission::InboundAllocate->value);

    return $actor;
}

/** @return array{PurchaseOrder, PurchaseOrderLine, PurchaseInbound, PurchaseInboundLine} */
function inboundDeepFixture(
    string $quantity = '10.000000',
    ?Supplier $supplier = null,
): array {
    $order = PurchaseOrder::factory()->accepted()->create([
        'supplier_id' => $supplier?->getKey() ?? Supplier::factory(),
    ]);
    $line = PurchaseOrderLine::factory()->for($order)->create([
        'quantity_ordered' => $quantity,
        'transaction_quantity' => $quantity,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => $quantity,
        'received_base_quantity' => '0.000000',
    ]);
    $inbound = PurchaseInbound::factory()->for($order)->create();
    $inboundLine = PurchaseInboundLine::factory()->create([
        'purchase_inbound_id' => $inbound->getKey(),
        'purchase_order_line_id' => $line->getKey(),
    ]);

    return [$order, $line, $inbound, $inboundLine];
}

function inboundDeepMethod(string $name): ReflectionMethod
{
    return new ReflectionMethod(PurchaseInboundService::class, $name);
}

it('rejects moving an update onto another allocation warehouse', function (): void {
    [, , , $line] = inboundDeepFixture();
    $actor = inboundDeepActor();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);
    $service = app(PurchaseInboundService::class);

    $allocationA = $service->allocate($actor, $line, $warehouseA, '4.000000');
    $service->allocate($actor, $line, $warehouseB, '3.000000');

    expect(fn () => $service->updateAllocation(
        $actor,
        $line,
        $allocationA,
        $warehouseB,
        '4.000000',
    ))->toThrow(InvalidPurchaseInboundAllocation::class, 'already allocated');
});

it('resolves no single and ambiguous receiving warehouses', function (): void {
    $service = app(PurchaseInboundService::class);

    $unallocatedOrder = PurchaseOrder::factory()->accepted()->create();
    expect(fn () => $service->resolveReceivingWarehouse($unallocatedOrder))
        ->toThrow(PurchaseOrderNotAllocated::class);

    [$emptyOrder] = inboundDeepFixture();
    expect(fn () => $service->resolveReceivingWarehouse($emptyOrder))
        ->toThrow(PurchaseOrderNotAllocated::class);

    [$singleOrder, , , $singleLine] = inboundDeepFixture();
    $singleWarehouse = Warehouse::factory()->create(['is_active' => true]);
    PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $singleLine->getKey(),
        'warehouse_id' => $singleWarehouse->getKey(),
        'allocated_base_quantity' => '10.000000',
    ]);

    expect($service->resolveReceivingWarehouse($singleOrder)->is($singleWarehouse))->toBeTrue();

    [$splitOrder, , , $splitLine] = inboundDeepFixture();
    $splitA = Warehouse::factory()->create(['is_active' => true]);
    $splitB = Warehouse::factory()->create(['is_active' => true]);
    PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $splitLine->getKey(),
        'warehouse_id' => $splitA->getKey(),
        'allocated_base_quantity' => '5.000000',
    ]);
    PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $splitLine->getKey(),
        'warehouse_id' => $splitB->getKey(),
        'allocated_base_quantity' => '5.000000',
    ]);

    expect(fn () => $service->resolveReceivingWarehouse($splitOrder))
        ->toThrow(PurchaseOrderNotAllocated::class);
});

it('covers legacy allocation no commitment null quantity hydration and committed move guard', function (): void {
    $actor = inboundDeepActor();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);
    $service = app(PurchaseInboundService::class);

    $requiresConfirmation = Supplier::factory()->create(['requires_confirmation' => true]);
    [, , , $unconfirmedLine] = inboundDeepFixture('10.000000', $requiresConfirmation);

    expect(fn () => $service->allocate($actor, $unconfirmedLine, $warehouseA))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'supplier-confirmed quantity');

    [, $orderLine, , $line] = inboundDeepFixture('10.000000');
    $historical = PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $line->getKey(),
        'warehouse_id' => $warehouseA->getKey(),
        'allocated_base_quantity' => null,
    ]);

    $hydrated = $service->allocate($actor, $line, $warehouseA);

    expect($hydrated->getKey())->toBe($historical->getKey())
        ->and($hydrated->allocated_base_quantity)->toBe('10.000000');

    $operation = InventoryOperation::factory()->receipt()->done()->create([
        'destination_warehouse_id' => $warehouseA->getKey(),
        'supplier_id' => $orderLine->purchaseOrder->supplier_id,
    ]);
    InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $orderLine->product_variant_id,
        'unit_id' => $orderLine->unit_id,
        'quantity' => '1.000000',
        'transaction_quantity' => '1.000000',
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
        'purchase_order_line_id' => $orderLine->getKey(),
        'purchase_inbound_allocation_id' => $historical->getKey(),
    ]);

    expect(fn () => $service->allocate($actor, $line, $warehouseB))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'cannot be moved');
});

it('covers unresolved historical allocation and reduced supplier commitment guards', function (): void {
    $service = app(PurchaseInboundService::class);

    [, $orderLine, , $line] = inboundDeepFixture('10.000000');
    $warehouse = Warehouse::factory()->create(['is_active' => true]);
    $unresolved = PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $line->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'allocated_base_quantity' => null,
    ]);

    expect(fn (): mixed => inboundDeepMethod('assertAllocationFits')->invoke(
        $service,
        $line,
        $orderLine,
        new Collection([$unresolved]),
        '1.000000',
    ))->toThrow(InvalidPurchaseInboundAllocation::class, 'historical');

    $requiresConfirmation = Supplier::factory()->create(['requires_confirmation' => true]);
    [, $confirmedOrderLine, , $confirmedLine] = inboundDeepFixture('10.000000', $requiresConfirmation);
    $overHistorical = PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $confirmedLine->getKey(),
        'warehouse_id' => Warehouse::factory()->create(['is_active' => true])->getKey(),
        'allocated_base_quantity' => '5.000000',
    ]);

    expect(fn (): mixed => inboundDeepMethod('assertAllocationFits')->invoke(
        $service,
        $confirmedLine,
        $confirmedOrderLine,
        new Collection([$overHistorical]),
        '1.000000',
    ))->toThrow(InvalidPurchaseInboundAllocation::class, 'Supplier commitment');
});
