<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\PurchaseInboundStatus;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\Exceptions\InvalidPurchaseInboundAllocation;
use App\Services\Purchasing\PurchaseInboundService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function phaseFourAllocationActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(InventoryPermission::InboundAllocate->value);

    return $actor;
}

/**
 * @return array{0: PurchaseOrder, 1: PurchaseOrderLine, 2: PurchaseInbound, 3: PurchaseInboundLine}
 */
function phaseFourInboundLine(string $baseQuantity = '100.000000'): array
{
    $order = PurchaseOrder::factory()->accepted()->create();
    $purchaseOrderLine = PurchaseOrderLine::factory()->for($order)->create([
        'quantity_ordered' => $baseQuantity,
        'transaction_quantity' => $baseQuantity,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => $baseQuantity,
        'received_base_quantity' => '0.000000',
    ]);
    $inbound = PurchaseInbound::factory()->for($order)->create();
    $line = PurchaseInboundLine::factory()->create([
        'purchase_inbound_id' => $inbound->getKey(),
        'purchase_order_line_id' => $purchaseOrderLine->getKey(),
    ]);

    return [$order, $purchaseOrderLine, $inbound, $line];
}

function phaseFourRecordReceivedQuantity(
    PurchaseOrderLine $purchaseOrderLine,
    PurchaseInboundAllocation $allocation,
    Warehouse $warehouse,
    string $baseQuantity,
): void {
    $operation = InventoryOperation::factory()->receipt()->done()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $purchaseOrderLine->purchaseOrder->supplier_id,
    ]);

    InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $purchaseOrderLine->product_variant_id,
        'unit_id' => $purchaseOrderLine->unit_id,
        'quantity' => $baseQuantity,
        'transaction_quantity' => $baseQuantity,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => $baseQuantity,
        'purchase_order_line_id' => $purchaseOrderLine->getKey(),
        'purchase_inbound_allocation_id' => $allocation->getKey(),
    ]);
}

it('splits one inbound line across multiple warehouses and advances status only when fully allocated', function (): void {
    [, , $inbound, $line] = phaseFourInboundLine();
    $actor = phaseFourAllocationActor();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);
    $service = app(PurchaseInboundService::class);

    $allocationA = $service->allocate($actor, $line, $warehouseA, '60');

    expect($allocationA->allocated_base_quantity)->toBe('60.000000')
        ->and($inbound->fresh()->status)->toBe(PurchaseInboundStatus::AwaitingAllocation)
        ->and($line->fresh()->allocatedBaseQuantity())->toBe('60.000000')
        ->and($line->fresh()->unallocatedBaseQuantity())->toBe('40.000000');

    $allocationB = $service->allocate($actor, $line, $warehouseB, '40.000000');

    expect($allocationB->allocated_base_quantity)->toBe('40.000000')
        ->and($line->fresh()->allocations()->count())->toBe(2)
        ->and($line->fresh()->allocatedBaseQuantity())->toBe('100.000000')
        ->and($line->fresh()->unallocatedBaseQuantity())->toBe('0.000000')
        ->and($inbound->fresh()->status)->toBe(PurchaseInboundStatus::AwaitingReceipt)
        ->and($inbound->fresh()->allocation_confirmed_at)->not->toBeNull();
});

it('rejects allocation totals above the inbound base quantity', function (): void {
    [, , , $line] = phaseFourInboundLine();
    $actor = phaseFourAllocationActor();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);
    $service = app(PurchaseInboundService::class);

    $service->allocate($actor, $line, $warehouseA, '60');

    expect(fn () => $service->allocate($actor, $line, $warehouseB, '50'))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'exceeds the inbound base quantity');

    expect($line->fresh()->allocations()->count())->toBe(1)
        ->and($line->fresh()->allocatedBaseQuantity())->toBe('60.000000');
});

it('rejects zero negative and over-precision allocation quantities', function (string $quantity): void {
    [, , , $line] = phaseFourInboundLine();
    $actor = phaseFourAllocationActor();
    $warehouse = Warehouse::factory()->create(['is_active' => true]);

    expect(fn () => app(PurchaseInboundService::class)->allocate($actor, $line, $warehouse, $quantity))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'positive decimal');
})->with([
    'zero' => '0',
    'negative' => '-1',
    'too precise' => '1.0000001',
]);

it('rejects a duplicate warehouse row and requires updates through the canonical update method', function (): void {
    [, , , $line] = phaseFourInboundLine();
    $actor = phaseFourAllocationActor();
    $warehouse = Warehouse::factory()->create(['is_active' => true]);
    $service = app(PurchaseInboundService::class);

    $service->allocate($actor, $line, $warehouse, '60');

    expect(fn () => $service->allocate($actor, $line, $warehouse, '10'))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'already allocated');

    expect($line->fresh()->allocations()->count())->toBe(1);
});

it('rejects inactive warehouses', function (): void {
    [, , , $line] = phaseFourInboundLine();
    $actor = phaseFourAllocationActor();
    $warehouse = Warehouse::factory()->create(['is_active' => false]);

    expect(fn () => app(PurchaseInboundService::class)->allocate($actor, $line, $warehouse, '10'))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'inactive or unavailable');
});

it('updates an allocation while preserving the total-allocation invariant', function (): void {
    [, , , $line] = phaseFourInboundLine();
    $actor = phaseFourAllocationActor();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);
    $service = app(PurchaseInboundService::class);

    $allocationA = $service->allocate($actor, $line, $warehouseA, '60');
    $service->allocate($actor, $line, $warehouseB, '30');

    $updated = $service->updateAllocation($actor, $line, $allocationA, $warehouseA, '70');

    expect($updated->allocated_base_quantity)->toBe('70.000000')
        ->and($line->fresh()->allocatedBaseQuantity())->toBe('100.000000');

    expect(fn () => $service->updateAllocation($actor, $line, $updated, $warehouseA, '71'))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'exceeds the inbound base quantity');
});

it('allows reduction to received quantity but rejects reduction below it', function (): void {
    [, $purchaseOrderLine, , $line] = phaseFourInboundLine();
    $actor = phaseFourAllocationActor();
    $warehouse = Warehouse::factory()->create(['is_active' => true]);
    $service = app(PurchaseInboundService::class);
    $allocation = $service->allocate($actor, $line, $warehouse, '60');

    phaseFourRecordReceivedQuantity($purchaseOrderLine, $allocation, $warehouse, '25.000000');

    $updated = $service->updateAllocation($actor, $line, $allocation, $warehouse, '25');

    expect($updated->allocated_base_quantity)->toBe('25.000000');

    expect(fn () => $service->updateAllocation($actor, $line, $updated, $warehouse, '24.999999'))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'below the already received');
});

it('does not move an allocation to another warehouse after receiving has started', function (): void {
    [, $purchaseOrderLine, , $line] = phaseFourInboundLine();
    $actor = phaseFourAllocationActor();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);
    $service = app(PurchaseInboundService::class);
    $allocation = $service->allocate($actor, $line, $warehouseA, '60');

    phaseFourRecordReceivedQuantity($purchaseOrderLine, $allocation, $warehouseA, '25.000000');

    expect(fn () => $service->updateAllocation($actor, $line, $allocation, $warehouseB, '60'))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'cannot be moved');
});

it('deletes an unused allocation but refuses to delete one with completed receipt quantity', function (): void {
    [, $purchaseOrderLine, , $line] = phaseFourInboundLine();
    $actor = phaseFourAllocationActor();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);
    $service = app(PurchaseInboundService::class);

    $unused = $service->allocate($actor, $line, $warehouseA, '40');
    $received = $service->allocate($actor, $line, $warehouseB, '60');
    phaseFourRecordReceivedQuantity($purchaseOrderLine, $received, $warehouseB, '10.000000');

    $service->removeAllocation($actor, $line, $unused);

    expect(PurchaseInboundAllocation::query()->whereKey($unused->getKey())->exists())->toBeFalse();

    expect(fn () => $service->removeAllocation($actor, $line, $received))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'cannot be deleted');
});

it('rejects an allocation from another inbound line when updating or deleting', function (): void {
    [, , , $lineA] = phaseFourInboundLine();
    [, , , $lineB] = phaseFourInboundLine();
    $actor = phaseFourAllocationActor();
    $warehouse = Warehouse::factory()->create(['is_active' => true]);
    $service = app(PurchaseInboundService::class);
    $allocation = $service->allocate($actor, $lineA, $warehouse, '10');

    expect(fn () => $service->updateAllocation($actor, $lineB, $allocation, $warehouse, '10'))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'does not belong');

    expect(fn () => $service->removeAllocation($actor, $lineB, $allocation))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'does not belong');
});

it('keeps the legacy single-warehouse allocate call functional without guessing a split quantity', function (): void {
    [, , $inbound, $line] = phaseFourInboundLine();
    $actor = phaseFourAllocationActor();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);
    $service = app(PurchaseInboundService::class);

    $allocation = $service->allocate($actor, $line, $warehouseA);

    expect($allocation->allocated_base_quantity)->toBe('100.000000')
        ->and($inbound->fresh()->status)->toBe(PurchaseInboundStatus::AwaitingReceipt);

    $moved = $service->allocate($actor, $line, $warehouseB);

    expect($moved->getKey())->toBe($allocation->getKey())
        ->and($moved->warehouse_id)->toBe($warehouseB->getKey())
        ->and($moved->allocated_base_quantity)->toBe('100.000000')
        ->and($line->fresh()->allocations()->count())->toBe(1);
});

it('requires explicit quantity when legacy callers encounter an already split line', function (): void {
    [, , , $line] = phaseFourInboundLine();
    $actor = phaseFourAllocationActor();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);
    $warehouseC = Warehouse::factory()->create(['is_active' => true]);
    $service = app(PurchaseInboundService::class);

    $service->allocate($actor, $line, $warehouseA, '60');
    $service->allocate($actor, $line, $warehouseB, '40');

    expect(fn () => $service->allocate($actor, $line, $warehouseC))
        ->toThrow(InvalidPurchaseInboundAllocation::class, 'explicit allocated quantity is required');
});

it('enforces allocation authorization in the service layer', function (): void {
    [, , , $line] = phaseFourInboundLine();
    $actor = User::factory()->create();
    $warehouse = Warehouse::factory()->create(['is_active' => true]);

    expect(fn () => app(PurchaseInboundService::class)->allocate($actor, $line, $warehouse, '10'))
        ->toThrow(AuthorizationException::class);
});
