<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Purchasing\Exceptions\InvalidPurchaseInboundReceipt;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotAllocated;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();

    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);

    $this->receiving = app(PurchaseOrderReceivingService::class);
    $this->operations = app(InventoryOperationService::class);
});

function phaseFourReceivingAllocator(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::InboundAllocate->value);

    return $user;
}

/**
 * @return array{
 *     order: PurchaseOrder,
 *     line: PurchaseOrderLine,
 *     warehouse_a: Warehouse,
 *     warehouse_b: Warehouse,
 *     allocation_a: PurchaseInboundAllocation,
 *     allocation_b: PurchaseInboundAllocation
 * }
 */
function phaseFourReceivingOrder(): array
{
    $variant = ProductVariant::factory()->create();
    /** @var Unit $unit */
    $unit = $variant->unit()->firstOrFail();
    $order = PurchaseOrder::factory()->sent()->create();

    /** @var PurchaseOrderLine $line */
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => '100',
        'unit_cost' => '5.00',
        'line_total' => '500.00',
    ]);

    $snapshot = app(QuantityNormalizer::class)->normalize($variant, (int) $unit->getKey(), '100');
    $line->forceFill([
        'transaction_quantity' => $snapshot->transactionQuantity,
        'transaction_unit_id' => $snapshot->transactionUnitId,
        'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
        'base_quantity' => $snapshot->baseQuantity,
        'received_base_quantity' => '0.000000',
    ])->save();

    $inboundService = app(PurchaseInboundService::class);
    $inbound = $inboundService->ensureForAccepted($order);
    $inboundLine = $inbound->lines()->where('purchase_order_line_id', $line->getKey())->firstOrFail();
    $warehouseA = Warehouse::factory()->create(['is_active' => true]);
    $warehouseB = Warehouse::factory()->create(['is_active' => true]);
    $allocator = phaseFourReceivingAllocator();

    $allocationA = $inboundService->allocate($allocator, $inboundLine, $warehouseA, '60');
    $allocationB = $inboundService->allocate($allocator, $inboundLine, $warehouseB, '40');

    return [
        'order' => $order->refresh(),
        'line' => $line->refresh(),
        'warehouse_a' => $warehouseA,
        'warehouse_b' => $warehouseB,
        'allocation_a' => $allocationA,
        'allocation_b' => $allocationB,
    ];
}

it('creates a draft receipt against one explicit inbound allocation with canonical provenance', function (): void {
    $context = phaseFourReceivingOrder();

    $operation = $this->receiving->initiate($this->manager, $context['order'], [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => '30',
    ]]);

    $line = $operation->lines->sole();

    expect($operation->operation_type)->toBe(OperationType::Receipt)
        ->and($operation->stage)->toBe(OperationStage::Draft)
        ->and($operation->destination_warehouse_id)->toBe($context['warehouse_a']->getKey())
        ->and($line->purchase_inbound_allocation_id)->toBe($context['allocation_a']->getKey())
        ->and($line->purchase_order_line_id)->toBe($context['line']->getKey())
        ->and($line->base_quantity)->toBe('30.000000')
        ->and($line->quantity)->toBe('30.000000')
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('rejects one receipt that mixes allocations from different warehouses', function (): void {
    $context = phaseFourReceivingOrder();

    expect(fn (): InventoryOperation => $this->receiving->initiate($this->manager, $context['order'], [
        ['purchase_inbound_allocation_id' => $context['allocation_a']->getKey(), 'quantity' => '10'],
        ['purchase_inbound_allocation_id' => $context['allocation_b']->getKey(), 'quantity' => '10'],
    ]))->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('rejects a receipt quantity above the allocation remaining quantity', function (): void {
    $context = phaseFourReceivingOrder();

    expect(fn (): InventoryOperation => $this->receiving->initiate($this->manager, $context['order'], [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => '60.000001',
    ]]))->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('treats non-cancelled draft receipt lines as allocation reservations', function (): void {
    $context = phaseFourReceivingOrder();

    $this->receiving->initiate($this->manager, $context['order'], [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => '40',
    ]]);

    expect(fn (): InventoryOperation => $this->receiving->initiate($this->manager, $context['order'], [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => '30',
    ]]))->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('also enforces the purchase-order line remaining quantity', function (): void {
    $context = phaseFourReceivingOrder();

    $context['line']->forceFill([
        'received_base_quantity' => '95.000000',
        'quantity_received' => '95.000000',
    ])->save();

    expect(fn (): InventoryOperation => $this->receiving->initiate($this->manager, $context['order']->refresh(), [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => '10',
    ]]))->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('never guesses a warehouse for legacy initiation when split allocations remain', function (): void {
    $context = phaseFourReceivingOrder();

    expect(fn (): InventoryOperation => $this->receiving->initiate($this->manager, $context['order']))
        ->toThrow(PurchaseOrderNotAllocated::class);
});

it('completes one PO line across warehouse allocations while preserving each allocation provenance', function (): void {
    $context = phaseFourReceivingOrder();

    foreach ([
        [$context['allocation_a'], '30'],
        [$context['allocation_b'], '40'],
        [$context['allocation_a'], '30'],
    ] as [$allocation, $quantity]) {
        $operation = $this->receiving->initiate($this->manager, $context['order']->refresh(), [[
            'purchase_inbound_allocation_id' => $allocation->getKey(),
            'quantity' => $quantity,
        ]]);
        $this->operations->markReady($operation, $this->manager);
        $this->operations->complete($operation->refresh(), $this->manager);
    }

    expect($context['order']->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($context['line']->fresh()->received_base_quantity)->toBe('100.000000')
        ->and($context['allocation_a']->fresh()->receivedBaseQuantity())->toBe('60.000000')
        ->and($context['allocation_b']->fresh()->receivedBaseQuantity())->toBe('40.000000');
});

it('prevents an allocation-backed draft receipt identity from being edited', function (): void {
    $context = phaseFourReceivingOrder();
    $operation = $this->receiving->initiate($this->manager, $context['order'], [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => '30',
    ]]);

    expect(fn () => $operation->lines()->firstOrFail()->update(['quantity' => '70']))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and($operation->fresh()->stage)->toBe(OperationStage::Draft)
        ->and($context['allocation_a']->fresh()->receivedBaseQuantity())->toBe('0.000000');
});

it('backfills receipt provenance at completion only when PO line and warehouse identify one allocation', function (): void {
    $context = phaseFourReceivingOrder();

    $operation = $context['order']->receipts()->create([
        'operation_type' => OperationType::Receipt,
        'destination_warehouse_id' => $context['warehouse_a']->getKey(),
        'supplier_id' => $context['order']->supplier_id,
    ]);
    $operation->lines()->create([
        'product_variant_id' => $context['line']->product_variant_id,
        'unit_id' => $context['line']->unit_id,
        'quantity' => '10',
        'purchase_order_line_id' => $context['line']->getKey(),
    ]);

    $this->operations->markReady($operation->refresh(), $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    expect($operation->lines()->firstOrFail()->purchase_inbound_allocation_id)
        ->toBe($context['allocation_a']->getKey());
});

it('rejects completion when receipt destination no longer matches its allocation warehouse', function (): void {
    $context = phaseFourReceivingOrder();
    $operation = $this->receiving->initiate($this->manager, $context['order'], [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => '10',
    ]]);

    $operation->forceFill(['destination_warehouse_id' => $context['warehouse_b']->getKey()])->save();
    $this->operations->markReady($operation->refresh(), $this->manager);

    expect(fn (): InventoryOperation => $this->operations->complete($operation->refresh(), $this->manager))
        ->toThrow(InvalidPurchaseInboundReceipt::class);

    expect(InventoryMovement::query()->count())->toBe(0);
});
