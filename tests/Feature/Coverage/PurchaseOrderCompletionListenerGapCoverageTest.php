<?php

declare(strict_types=1);

use App\Listeners\AdvancePurchaseOrderOnOperationCompleted;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Warehouse;
use App\Services\Purchasing\Exceptions\InvalidPurchaseInboundReceipt;
use App\Services\Purchasing\Exceptions\OverReceiptRejected;
use App\Services\Purchasing\PurchaseInboundService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function poCompletionListener(): AdvancePurchaseOrderOnOperationCompleted
{
    return app(AdvancePurchaseOrderOnOperationCompleted::class);
}

function poCompletionMethod(string $name): ReflectionMethod
{
    return new ReflectionMethod(AdvancePurchaseOrderOnOperationCompleted::class, $name);
}

/** @return array{order:PurchaseOrder,line:PurchaseOrderLine,warehouse:Warehouse,inbound_line_id:int} */
function listenerInboundContext(): array
{
    $variant = ProductVariant::factory()->create();
    $order = PurchaseOrder::factory()->sent()->create();
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '10.000000',
        'transaction_quantity' => '10.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '10.000000',
        'received_base_quantity' => '0.000000',
        'unit_cost' => '1.00',
        'line_total' => '10.00',
    ]);
    $inbound = app(PurchaseInboundService::class)->ensureForAccepted($order);
    $inboundLine = $inbound->lines()->where('purchase_order_line_id', $line->getKey())->firstOrFail();
    $warehouse = Warehouse::factory()->create(['is_active' => true]);

    return [
        'order' => $order->refresh(),
        'line' => $line->refresh(),
        'warehouse' => $warehouse,
        'inbound_line_id' => (int) $inboundLine->getKey(),
    ];
}

function listenerReceipt(PurchaseOrder $order, ?int $warehouseId): InventoryOperation
{
    return InventoryOperation::factory()->receipt()->draft()->create([
        'source_document_type' => PurchaseOrder::class,
        'source_document_id' => $order->getKey(),
        'destination_warehouse_id' => $warehouseId,
        'supplier_id' => $order->supplier_id,
    ]);
}

it('covers missing inbound and destination warehouse provenance guards', function (): void {
    $variant = ProductVariant::factory()->create();
    $order = PurchaseOrder::factory()->sent()->create();
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '1',
        'unit_cost' => '1.00',
    ]);
    $warehouse = Warehouse::factory()->create();
    $operation = listenerReceipt($order, (int) $warehouse->getKey());
    InventoryOperationLine::factory()->for($operation, 'operation')->create([
        'purchase_order_line_id' => $line->getKey(),
        'base_quantity' => '1.000000',
    ]);

    expect(fn (): mixed => poCompletionMethod('lockAndValidateAllocationContext')
        ->invoke(poCompletionListener(), $operation, $order))
        ->toThrow(InvalidPurchaseInboundReceipt::class);

    $context = listenerInboundContext();
    $operation = listenerReceipt($context['order'], null);
    InventoryOperationLine::factory()->for($operation, 'operation')->create([
        'purchase_order_line_id' => $context['line']->getKey(),
        'base_quantity' => '1.000000',
    ]);

    expect(fn (): mixed => poCompletionMethod('lockAndValidateAllocationContext')
        ->invoke(poCompletionListener(), $operation, $context['order']))
        ->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('covers invalid purchase-order-line provenance guards', function (): void {
    $context = listenerInboundContext();
    $operation = listenerReceipt($context['order'], (int) $context['warehouse']->getKey());

    $allocation = PurchaseInboundAllocation::factory()->create([
        'warehouse_id' => $context['warehouse']->getKey(),
        'allocated_base_quantity' => '1.000000',
    ]);
    InventoryOperationLine::factory()->for($operation, 'operation')->create([
        'purchase_order_line_id' => null,
        'purchase_inbound_allocation_id' => $allocation->getKey(),
        'base_quantity' => '1.000000',
    ]);

    expect(fn (): mixed => poCompletionMethod('lockAndValidateAllocationContext')
        ->invoke(poCompletionListener(), $operation, $context['order']))
        ->toThrow(InvalidPurchaseInboundReceipt::class);

    $otherVariant = ProductVariant::factory()->create();
    $otherOrder = PurchaseOrder::factory()->sent()->create();
    $otherLine = $otherOrder->lines()->create([
        'product_variant_id' => $otherVariant->getKey(),
        'unit_id' => $otherVariant->unit_id,
        'quantity_ordered' => '1',
        'unit_cost' => '1.00',
    ]);

    $operation = listenerReceipt($context['order'], (int) $context['warehouse']->getKey());
    InventoryOperationLine::factory()->for($operation, 'operation')->create([
        'purchase_order_line_id' => $otherLine->getKey(),
        'base_quantity' => '1.000000',
    ]);

    expect(fn (): mixed => poCompletionMethod('lockAndValidateAllocationContext')
        ->invoke(poCompletionListener(), $operation, $context['order']))
        ->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('covers missing inbound-line deterministic allocation guard', function (): void {
    $context = listenerInboundContext();
    DB::table('purchase_inbound_lines')->where('id', $context['inbound_line_id'])->delete();

    $operation = listenerReceipt($context['order'], (int) $context['warehouse']->getKey());
    InventoryOperationLine::factory()->for($operation, 'operation')->create([
        'purchase_order_line_id' => $context['line']->getKey(),
        'base_quantity' => '1.000000',
    ]);

    expect(fn (): mixed => poCompletionMethod('lockAndValidateAllocationContext')
        ->invoke(poCompletionListener(), $operation, $context['order']))
        ->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('covers ambiguous or missing deterministic allocation guard', function (): void {
    $context = listenerInboundContext();
    $operation = listenerReceipt($context['order'], (int) $context['warehouse']->getKey());
    InventoryOperationLine::factory()->for($operation, 'operation')->create([
        'purchase_order_line_id' => $context['line']->getKey(),
        'base_quantity' => '1.000000',
    ]);

    expect(fn (): mixed => poCompletionMethod('lockAndValidateAllocationContext')
        ->invoke(poCompletionListener(), $operation, $context['order']))
        ->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('covers mismatched allocation guard', function (): void {
    $context = listenerInboundContext();
    $variant = ProductVariant::factory()->create();
    $secondLine = $context['order']->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '2',
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
        'received_base_quantity' => '0.000000',
        'unit_cost' => '1.00',
        'line_total' => '2.00',
    ]);
    $inbound = app(PurchaseInboundService::class)->ensureForAccepted($context['order']->refresh());
    $secondInboundLine = $inbound->lines()->where('purchase_order_line_id', $secondLine->getKey())->firstOrFail();
    $allocation = PurchaseInboundAllocation::factory()->create([
        'purchase_inbound_line_id' => $secondInboundLine->getKey(),
        'warehouse_id' => $context['warehouse']->getKey(),
        'allocated_base_quantity' => '1.000000',
    ]);

    $operation = listenerReceipt($context['order'], (int) $context['warehouse']->getKey());
    InventoryOperationLine::factory()->for($operation, 'operation')->create([
        'purchase_order_line_id' => $context['line']->getKey(),
        'purchase_inbound_allocation_id' => $allocation->getKey(),
        'base_quantity' => '1.000000',
    ]);

    expect(fn (): mixed => poCompletionMethod('lockAndValidateAllocationContext')
        ->invoke(poCompletionListener(), $operation, $context['order']))
        ->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('covers unresolved and over-received allocation guards', function (): void {
    $warehouse = Warehouse::factory()->create();
    $allocation = PurchaseInboundAllocation::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'allocated_base_quantity' => null,
    ]);

    expect(fn (): mixed => poCompletionMethod('assertAllocationsNotOverReceived')
        ->invoke(poCompletionListener(), new Collection([$allocation])))
        ->toThrow(InvalidPurchaseInboundReceipt::class);

    $allocation = PurchaseInboundAllocation::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'allocated_base_quantity' => '1.000000',
    ]);
    $receipt = InventoryOperation::factory()->receipt()->draft()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
    InventoryOperationLine::factory()->for($receipt, 'operation')->create([
        'purchase_inbound_allocation_id' => $allocation->getKey(),
        'base_quantity' => '2.000000',
    ]);

    expect(fn (): mixed => poCompletionMethod('assertAllocationsNotOverReceived')
        ->invoke(poCompletionListener(), new Collection([$allocation])))
        ->toThrow(InvalidPurchaseInboundReceipt::class);
});

it('covers receipt quantity and purchase-order line guards', function (): void {
    $operation = InventoryOperation::factory()->receipt()->draft()->create();
    $purchaseOrderLine = PurchaseOrderLine::factory()->create();
    InventoryOperationLine::factory()->for($operation, 'operation')->create([
        'purchase_order_line_id' => $purchaseOrderLine->getKey(),
        'base_quantity' => null,
    ]);

    expect(fn (): mixed => poCompletionMethod('receivedQuantitiesByPurchaseOrderLine')
        ->invoke(poCompletionListener(), $operation))
        ->toThrow(OverReceiptRejected::class);

    $line = new PurchaseOrderLine;
    $line->forceFill(['id' => 1, 'base_quantity' => null, 'conversion_factor_snapshot' => null]);

    expect(poCompletionMethod('assertNoOverReceipt')
        ->invoke(poCompletionListener(), new Collection([$line]), [1 => ['base_quantity' => '0.000000']]))
        ->toBeNull();

    expect(fn (): mixed => poCompletionMethod('assertNoOverReceipt')
        ->invoke(poCompletionListener(), new Collection([$line]), [1 => ['base_quantity' => '1.000000']]))
        ->toThrow(OverReceiptRejected::class);

    expect(poCompletionMethod('applyReceipts')
        ->invoke(poCompletionListener(), new Collection([$line]), []))
        ->toBeNull();

    expect(poCompletionMethod('applyReceipts')
        ->invoke(poCompletionListener(), new Collection([$line]), [1 => ['base_quantity' => '0.000000']]))
        ->toBeNull();

    expect(fn (): mixed => poCompletionMethod('applyReceipts')
        ->invoke(poCompletionListener(), new Collection([$line]), [1 => ['base_quantity' => '1.000000']]))
        ->toThrow(OverReceiptRejected::class);
});
