<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Purchasing\Exceptions\OverReceiptRejected;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * SC-004. The owner chose hard blocking over a configurable tolerance (D7), so
 * there is no threshold to relax and no setting to get wrong.
 *
 * The check runs under a row lock inside the completing transaction, which is
 * what makes it safe: an optimistic check would let two concurrent completions
 * both read a stale `quantity_received` and both pass.
 */

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();
    $this->receiving = app(PurchaseOrderReceivingService::class);
    $this->operations = app(InventoryOperationService::class);
    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);
});

function orderForOverReceipt(float $ordered = 10): PurchaseOrder
{
    $variant = ProductVariant::factory()->create();
    $order = PurchaseOrder::factory()->sent()->create();

    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => $ordered,
        'unit_cost' => '5.00',
        'line_total' => 5 * $ordered,
    ]);

    $quantityInput = mb_rtrim(mb_rtrim(number_format($ordered, 6, '.', ''), '0'), '.');
    $snapshot = app(QuantityNormalizer::class)->normalize($variant, (int) $variant->unit_id, $quantityInput);
    $line->forceFill([
        'transaction_quantity' => $snapshot->transactionQuantity,
        'transaction_unit_id' => $snapshot->transactionUnitId,
        'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
        'base_quantity' => $snapshot->baseQuantity,
        'received_base_quantity' => '0.000000',
    ])->save();

    $allocator = User::factory()->create();
    $allocator->givePermissionTo(InventoryPermission::InboundAllocate->value);

    app(PurchaseInboundService::class)->allocateAllTo($allocator, $order, Warehouse::factory()->create());

    return $order->refresh();
}

function overReceiptAllocation(PurchaseOrder $order): PurchaseInboundAllocation
{
    return PurchaseInboundAllocation::query()
        ->whereHas(
            'purchaseInboundLine.purchaseInbound',
            fn (Builder $query): Builder => $query->where('purchase_order_id', $order->getKey()),
        )
        ->sole();
}

function corruptReceiptQuantity(int $lineId, string $quantity): void
{
    DB::table('inventory_operation_lines')
        ->where('id', $lineId)
        ->update([
            'quantity' => $quantity,
            'transaction_quantity' => $quantity,
            'base_quantity' => $quantity,
        ]);
}

it('rejects a receipt that would exceed the ordered quantity, naming the line (FR-040)', function (): void {
    $order = orderForOverReceipt(10);
    $variant = $order->lines()->firstOrFail()->productVariant;

    $operation = $this->receiving->initiate($this->manager, $order);
    corruptReceiptQuantity($operation->lines()->firstOrFail()->getKey(), '11.000000');

    $this->operations->markReady($operation->refresh(), $this->manager);

    expect(fn () => $this->operations->complete($operation->refresh(), $this->manager))
        ->toThrow(OverReceiptRejected::class, $variant->sku);
});

it('rolls the whole completion back, including the stock movement, when over-receipt is rejected', function (): void {
    $order = orderForOverReceipt(10);

    $operation = $this->receiving->initiate($this->manager, $order);
    corruptReceiptQuantity($operation->lines()->firstOrFail()->getKey(), '11.000000');
    $this->operations->markReady($operation->refresh(), $this->manager);

    try {
        $this->operations->complete($operation->refresh(), $this->manager);
    } catch (OverReceiptRejected) {
        // expected
    }

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and((float) $order->refresh()->lines()->firstOrFail()->quantity_received)->toBe(0.0)
        ->and($order->status)->toBe(PurchaseOrderStatus::Accepted);
});

it('rejects a second receipt that would push a partially received line past the order', function (): void {
    $order = orderForOverReceipt(10);
    $allocation = overReceiptAllocation($order);

    $first = $this->receiving->initiate($this->manager, $order, [[
        'purchase_inbound_allocation_id' => $allocation->getKey(),
        'quantity' => 7,
    ]]);
    $this->operations->markReady($first->refresh(), $this->manager);
    $this->operations->complete($first->refresh(), $this->manager);

    $second = $this->receiving->initiate($this->manager, $order->refresh(), [[
        'purchase_inbound_allocation_id' => $allocation->getKey(),
        'quantity' => 3,
    ]]);
    corruptReceiptQuantity($second->lines()->firstOrFail()->getKey(), '4.000000');
    $this->operations->markReady($second->refresh(), $this->manager);

    expect(fn () => $this->operations->complete($second->refresh(), $this->manager))
        ->toThrow(OverReceiptRejected::class);

    expect((float) $order->refresh()->lines()->firstOrFail()->quantity_received)->toBe(7.0);
});

it('accepts a receipt that fills the line exactly', function (): void {
    $order = orderForOverReceipt(10);

    $operation = $this->receiving->initiate($this->manager, $order);
    $this->operations->markReady($operation, $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Received);
});

it('accepts a fractional receipt that fills the line exactly across three parts', function (): void {
    $order = orderForOverReceipt(10);
    $allocation = overReceiptAllocation($order);

    foreach (['3.333', '3.333', '3.334'] as $quantity) {
        $operation = $this->receiving->initiate($this->manager, $order->refresh(), [[
            'purchase_inbound_allocation_id' => $allocation->getKey(),
            'quantity' => $quantity,
        ]]);
        $this->operations->markReady($operation->refresh(), $this->manager);
        $this->operations->complete($operation->refresh(), $this->manager);
    }

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and((float) $order->lines()->firstOrFail()->quantity_received)->toBe(10.0);
});

it('does not double-count when the same operation is completed once, whatever the listener wiring', function (): void {
    $order = orderForOverReceipt(10);
    $allocation = overReceiptAllocation($order);

    $operation = $this->receiving->initiate($this->manager, $order, [[
        'purchase_inbound_allocation_id' => $allocation->getKey(),
        'quantity' => 5,
    ]]);
    $this->operations->markReady($operation->refresh(), $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    expect((float) $order->refresh()->lines()->firstOrFail()->quantity_received)->toBe(5.0);
});
