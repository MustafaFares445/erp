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
use App\Services\Purchasing\Exceptions\PurchaseOrderNotReceivable;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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

it('creates one idempotent draft receipt when an inbound allocation is confirmed', function (): void {
    $context = phaseFourReceivingOrder();

    $first = $this->receiving->ensureDraftReceiptForAllocation($this->manager, $context['allocation_a']);
    $repeat = $this->receiving->ensureDraftReceiptForAllocation($this->manager, $context['allocation_a']->fresh());

    expect($repeat->getKey())->toBe($first->getKey())
        ->and($first->operation_type)->toBe(OperationType::Receipt)
        ->and($first->stage)->toBe(OperationStage::Draft)
        ->and($first->destination_warehouse_id)->toBe($context['warehouse_a']->getKey())
        ->and($first->lines()->sole()->purchase_inbound_allocation_id)->toBe($context['allocation_a']->getKey())
        ->and(InventoryOperation::query()->where('operation_type', OperationType::Receipt->value)->count())->toBe(1);
});

it('splits a serialized allocation into one receipt line per physical unit', function (): void {
    $variant = ProductVariant::factory()->machine()->create();
    $unit = $variant->unit()->firstOrFail();
    $order = PurchaseOrder::factory()->sent()->create();
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => '3',
        'unit_cost' => '5.00',
        'line_total' => '15.00',
    ]);

    $snapshot = app(QuantityNormalizer::class)->normalize($variant, (int) $unit->getKey(), '3');
    $line->forceFill([
        'transaction_quantity' => $snapshot->transactionQuantity,
        'transaction_unit_id' => $snapshot->transactionUnitId,
        'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
        'base_quantity' => $snapshot->baseQuantity,
        'received_base_quantity' => '0.000000',
    ])->save();

    $inbound = app(PurchaseInboundService::class)->ensureForAccepted($order);
    $inboundLine = $inbound->lines()->where('purchase_order_line_id', $line->getKey())->firstOrFail();
    $warehouse = Warehouse::factory()->create(['is_active' => true]);
    $allocation = app(PurchaseInboundService::class)->allocate(
        phaseFourReceivingAllocator(),
        $inboundLine,
        $warehouse,
        '3',
    );

    $operation = $this->receiving->ensureDraftReceiptForAllocation($this->manager, $allocation);

    expect($operation->lines)->toHaveCount(3)
        ->and($operation->lines->pluck('quantity')->unique()->all())->toBe(['1.000000'])
        ->and($operation->lines->pluck('base_quantity')->unique()->all())->toBe(['1.000000'])
        ->and($operation->lines->pluck('purchase_inbound_allocation_id')->unique()->all())->toBe([$allocation->getKey()]);
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

it('covers receipt request normalization and serialized fractional line splitting', function (): void {
    $normalizeRequests = new ReflectionMethod(PurchaseOrderReceivingService::class, 'normalizeRequests');
    $normalizeQuantity = new ReflectionMethod(PurchaseOrderReceivingService::class, 'normalizeReceiptQuantity');
    $receiptLineQuantities = new ReflectionMethod(PurchaseOrderReceivingService::class, 'receiptLineQuantities');

    expect(fn (): mixed => $normalizeRequests->invoke($this->receiving, [
        ['purchase_inbound_allocation_id' => 10, 'quantity' => '1'],
        ['purchase_inbound_allocation_id' => 10, 'quantity' => '2'],
    ]))->toThrow(InvalidPurchaseInboundReceipt::class)
        ->and(fn (): mixed => $normalizeQuantity->invoke($this->receiving, '1.1234567'))
        ->toThrow(InvalidPurchaseInboundReceipt::class)
        ->and(fn (): mixed => $normalizeQuantity->invoke($this->receiving, '0'))
        ->toThrow(InvalidPurchaseInboundReceipt::class);

    $variant = ProductVariant::factory()->machine()->create();
    $order = PurchaseOrder::factory()->sent()->create();
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '3',
        'unit_cost' => '1.00',
    ])->load('productVariant');

    expect($receiptLineQuantities->invoke($this->receiving, $line, '2.500000'))->toBe(['2.500000'])
        ->and($receiptLineQuantities->invoke($this->receiving, $line, '2.000000'))->toBe(['1.000000', '1.000000']);
});

it('covers receipt provenance and deterministic allocation guards', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $variant = ProductVariant::factory()->create();
    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '2',
        'unit_cost' => '1.00',
    ]);
    app(PurchaseInboundService::class)->ensureForAccepted($order);

    $deterministic = new ReflectionMethod(PurchaseOrderReceivingService::class, 'deterministicRequests');
    expect(fn (): mixed => $deterministic->invoke($this->receiving, $order->refresh()))
        ->toThrow(PurchaseOrderNotAllocated::class);

    $context = phaseFourReceivingOrder();
    $prepare = new ReflectionMethod(PurchaseOrderReceivingService::class, 'prepareReceiptLines');
    expect(fn (): mixed => $prepare->invoke($this->receiving, $context['order'], [[
        'purchase_inbound_allocation_id' => 999999,
        'quantity' => '1.000000',
    ]], false))->toThrow(InvalidPurchaseInboundReceipt::class);

    $other = phaseFourReceivingOrder();
    expect(fn (): mixed => $prepare->invoke($this->receiving, $context['order'], [[
        'purchase_inbound_allocation_id' => $other['allocation_a']->getKey(),
        'quantity' => '1.000000',
    ]], false))->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('covers receipt availability floors and the completed-receipt fallback aggregate', function (): void {
    $context = phaseFourReceivingOrder();

    $draft = InventoryOperation::factory()->receipt()->draft()->create([
        'destination_warehouse_id' => $context['warehouse_a']->getKey(),
        'supplier_id' => $context['order']->supplier_id,
        'source_document_type' => PurchaseOrder::class,
        'source_document_id' => $context['order']->getKey(),
    ]);
    $draft->lines()->create([
        'product_variant_id' => $context['line']->product_variant_id,
        'unit_id' => $context['line']->unit_id,
        'quantity' => '100.000000',
        'transaction_quantity' => '100.000000',
        'transaction_unit_id' => $context['line']->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '100.000000',
        'purchase_order_line_id' => $context['line']->getKey(),
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
    ]);

    $allocationAvailable = new ReflectionMethod(PurchaseOrderReceivingService::class, 'allocationAvailableForNewReceipt');
    expect($allocationAvailable->invoke($this->receiving, $context['allocation_a']->refresh()))->toBe('0.000000');

    $done = InventoryOperation::factory()->receipt()->done()->create([
        'destination_warehouse_id' => $context['warehouse_a']->getKey(),
        'supplier_id' => $context['order']->supplier_id,
        'source_document_type' => PurchaseOrder::class,
        'source_document_id' => $context['order']->getKey(),
    ]);
    $done->lines()->create([
        'product_variant_id' => $context['line']->product_variant_id,
        'unit_id' => $context['line']->unit_id,
        'quantity' => '150.000000',
        'transaction_quantity' => '150.000000',
        'transaction_unit_id' => $context['line']->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '150.000000',
        'purchase_order_line_id' => $context['line']->getKey(),
    ]);

    $line = $context['line']->refresh();
    $line->forceFill(['received_base_quantity' => null])->save();
    $line->refresh()->load('productVariant');

    $snapshotFor = new ReflectionMethod(PurchaseOrderReceivingService::class, 'snapshotFor');
    $snapshot = $snapshotFor->invoke($this->receiving, $line);
    $lineAvailable = new ReflectionMethod(PurchaseOrderReceivingService::class, 'purchaseOrderLineAvailableForNewReceipt');

    expect($lineAvailable->invoke($this->receiving, $context['order'], $line, $snapshot))->toBe('0.000000');
});

it('covers low-level receipt validation and guard helpers', function (): void {
    $normalizeRequests = new ReflectionMethod(PurchaseOrderReceivingService::class, 'normalizeRequests');
    $minimumQuantity = new ReflectionMethod(PurchaseOrderReceivingService::class, 'minimumQuantity');
    $aggregateQuantity = new ReflectionMethod(PurchaseOrderReceivingService::class, 'aggregateQuantity');
    $baseUnitId = new ReflectionMethod(PurchaseOrderReceivingService::class, 'baseUnitId');
    $assertWarehouse = new ReflectionMethod(PurchaseOrderReceivingService::class, 'assertWarehouseIsUsable');
    $receiptRequest = new ReflectionMethod(PurchaseOrderReceivingService::class, 'receiptRequestForAllocation');
    $allocationAvailable = new ReflectionMethod(PurchaseOrderReceivingService::class, 'allocationAvailableForNewReceipt');

    expect(fn (): mixed => $normalizeRequests->invoke($this->receiving, []))
        ->toThrow(InvalidPurchaseInboundReceipt::class)
        ->and(fn (): mixed => $normalizeRequests->invoke($this->receiving, [[
            'purchase_inbound_allocation_id' => 0,
            'quantity' => '1',
        ]]))->toThrow(InvalidPurchaseInboundReceipt::class)
        ->and($minimumQuantity->invoke($this->receiving, '5.000000', '3.000000', '4.000000'))
        ->toBe('3.000000')
        ->and($aggregateQuantity->invoke($this->receiving, '2.5'))->toBe('2.500000')
        ->and(fn (): mixed => $aggregateQuantity->invoke($this->receiving, 'not-numeric'))
        ->toThrow(LogicException::class);

    $variant = new ProductVariant;
    $variant->setRawAttributes(['unit_id' => 'invalid'], true);

    expect(fn (): mixed => $baseUnitId->invoke($this->receiving, $variant))
        ->toThrow(LogicException::class);

    $inactiveWarehouse = Warehouse::factory()->create(['is_active' => false]);
    expect(fn (): mixed => $assertWarehouse->invoke($this->receiving, $inactiveWarehouse))
        ->toThrow(InvalidPurchaseInboundReceipt::class);

    $allocation = new PurchaseInboundAllocation;
    $allocation->forceFill(['id' => 999999, 'allocated_base_quantity' => null]);
    expect(fn (): mixed => $receiptRequest->invoke($this->receiving, $allocation))
        ->toThrow(InvalidPurchaseInboundReceipt::class)
        ->and(fn (): mixed => $allocationAvailable->invoke($this->receiving, $allocation))
        ->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('covers missing inbound guards and a non-receivable purchase order', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $deterministic = new ReflectionMethod(PurchaseOrderReceivingService::class, 'deterministicRequests');
    $prepare = new ReflectionMethod(PurchaseOrderReceivingService::class, 'prepareReceiptLines');

    expect(fn (): mixed => $deterministic->invoke($this->receiving, $order))
        ->toThrow(PurchaseOrderNotAllocated::class)
        ->and(fn (): mixed => $prepare->invoke($this->receiving, $order, [[
            'purchase_inbound_allocation_id' => 1,
            'quantity' => '1.000000',
        ]], false))->toThrow(PurchaseOrderNotAllocated::class);

    $draft = PurchaseOrder::factory()->create();
    Gate::before(static fn (): bool => true);
    expect(fn (): InventoryOperation => $this->receiving->initiate($this->manager, $draft, [[
        'purchase_inbound_allocation_id' => 1,
        'quantity' => '1',
    ]]))->toThrow(PurchaseOrderNotReceivable::class);
});

it('backfills missing purchase receipt quantity snapshots', function (): void {
    $variant = ProductVariant::factory()->create();
    $order = PurchaseOrder::factory()->sent()->create();
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '4.000000',
        'quantity_received' => '1.000000',
        'unit_cost' => '2.00',
        'line_total' => '8.00',
    ]);
    $line->forceFill([
        'transaction_quantity' => null,
        'transaction_unit_id' => null,
        'conversion_factor_snapshot' => null,
        'base_quantity' => null,
        'quantity_received' => '1.000000',
        'received_base_quantity' => null,
    ])->save();
    $line->refresh()->load('productVariant');

    $snapshotFor = new ReflectionMethod(PurchaseOrderReceivingService::class, 'snapshotFor');
    $snapshot = $snapshotFor->invoke($this->receiving, $line);

    expect($snapshot->transactionQuantity)->toBe('4.000000')
        ->and($line->refresh()->transaction_quantity)->toBe('4.000000')
        ->and($line->received_base_quantity)->toBe('1.000000');

    $line->forceFill([
        'transaction_quantity' => null,
        'transaction_unit_id' => null,
        'conversion_factor_snapshot' => null,
        'base_quantity' => null,
        'quantity_received' => '0.000000',
        'received_base_quantity' => null,
    ])->save();
    $line->refresh()->load('productVariant');
    $snapshotFor->invoke($this->receiving, $line);

    expect($line->refresh()->received_base_quantity)->toBe('0.000000');
});

it('authorizes receipt initiation through the inbound permission fallback and denies without either permission', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    app(PurchaseInboundService::class)->ensureForAccepted($order);
    $authorize = new ReflectionMethod(PurchaseOrderReceivingService::class, 'authorizeReceiptInitiation');

    $inventoryReceiver = User::factory()->create();
    $inventoryReceiver->assignRole(DashboardRole::Reviewer->value);
    $inventoryReceiver->givePermissionTo(InventoryPermission::ReceiptCreate->value);

    expect($authorize->invoke($this->receiving, $inventoryReceiver, $order->refresh()))->toBeNull();

    $denied = User::factory()->create();
    $denied->assignRole(DashboardRole::Reviewer->value);

    expect(fn (): mixed => $authorize->invoke($this->receiving, $denied, $order->refresh()))
        ->toThrow(AuthorizationException::class);
});

it('covers unresolved allocation quantities in deterministic and prepared receipt paths', function (): void {
    $context = phaseFourReceivingOrder();
    $context['allocation_a']->forceFill(['allocated_base_quantity' => null])->save();

    $deterministic = new ReflectionMethod(PurchaseOrderReceivingService::class, 'deterministicRequests');
    expect(fn (): mixed => $deterministic->invoke($this->receiving, $context['order']->refresh()))
        ->toThrow(InvalidPurchaseInboundReceipt::class);

    $prepare = new ReflectionMethod(PurchaseOrderReceivingService::class, 'prepareReceiptLines');
    expect(fn (): mixed => $prepare->invoke($this->receiving, $context['order']->refresh(), [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => '1.000000',
    ]], false))->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('rejects corrupted purchase-inbound provenance and soft-deleted allocation warehouses', function (): void {
    $prepare = new ReflectionMethod(PurchaseOrderReceivingService::class, 'prepareReceiptLines');

    $context = phaseFourReceivingOrder();
    $otherOrder = PurchaseOrder::factory()->sent()->create();
    $otherVariant = ProductVariant::factory()->create();
    $otherLine = $otherOrder->lines()->create([
        'product_variant_id' => $otherVariant->getKey(),
        'unit_id' => $otherVariant->unit_id,
        'quantity_ordered' => '1.000000',
        'unit_cost' => '1.00',
        'line_total' => '1.00',
    ]);
    DB::table('purchase_inbound_lines')
        ->where('id', $context['allocation_a']->purchase_inbound_line_id)
        ->update(['purchase_order_line_id' => $otherLine->getKey()]);

    expect(fn (): mixed => $prepare->invoke($this->receiving, $context['order']->refresh(), [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => '1.000000',
    ]], false))->toThrow(InvalidPurchaseInboundReceipt::class);

    $context = phaseFourReceivingOrder();
    $context['warehouse_a']->delete();

    expect(fn (): mixed => $prepare->invoke($this->receiving, $context['order']->refresh(), [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => '1.000000',
    ]], false))->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('skips fully reserved legacy allocation requests and reports nothing available', function (): void {
    $context = phaseFourReceivingOrder();
    $quantity = $context['allocation_a']->allocated_base_quantity;

    $this->receiving->initiate($this->manager, $context['order'], [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => $quantity,
    ]]);

    $prepare = new ReflectionMethod(PurchaseOrderReceivingService::class, 'prepareReceiptLines');
    expect(fn (): mixed => $prepare->invoke($this->receiving, $context['order']->refresh(), [[
        'purchase_inbound_allocation_id' => $context['allocation_a']->getKey(),
        'quantity' => $quantity,
    ]], true))->toThrow(InvalidPurchaseInboundReceipt::class);
});
