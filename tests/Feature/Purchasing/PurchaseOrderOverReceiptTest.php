<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Purchasing\Exceptions\OverReceiptRejected;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    (new PurchasePermissionSeeder)->run();
    $this->receiving = app(PurchaseOrderReceivingService::class);
    $this->operations = app(InventoryOperationService::class);
    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);
});

function orderForOverReceipt(float $ordered = 10): PurchaseOrder
{
    $order = PurchaseOrder::factory()->sent()->create([
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
    ]);

    $order->lines()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => $ordered,
        'unit_cost' => '5.00',
        'line_total' => 5 * $ordered,
    ]);

    return $order->refresh();
}

it('rejects a receipt that would exceed the ordered quantity, naming the line (FR-040)', function (): void {
    $order = orderForOverReceipt(10);
    $variant = $order->lines()->firstOrFail()->productVariant;

    $operation = $this->receiving->initiate($this->manager, $order);
    $operation->lines()->firstOrFail()->update(['quantity' => 11]);

    $this->operations->markReady($operation->refresh(), $this->manager);

    expect(fn () => $this->operations->complete($operation->refresh(), $this->manager))
        ->toThrow(OverReceiptRejected::class, $variant->sku);
});

it('rolls the whole completion back, including the stock movement, when over-receipt is rejected', function (): void {
    // Throwing inside the completing transaction is the point: the receipt was
    // not legitimate, so neither is the stock it would have created.
    $order = orderForOverReceipt(10);

    $operation = $this->receiving->initiate($this->manager, $order);
    $operation->lines()->firstOrFail()->update(['quantity' => 11]);
    $this->operations->markReady($operation->refresh(), $this->manager);

    try {
        $this->operations->complete($operation->refresh(), $this->manager);
    } catch (OverReceiptRejected) {
        // expected
    }

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and((float) $order->refresh()->lines()->firstOrFail()->quantity_received)->toBe(0.0)
        ->and($order->status)->toBe(PurchaseOrderStatus::Sent);
});

it('rejects a second receipt that would push a partially received line past the order', function (): void {
    $order = orderForOverReceipt(10);

    $first = $this->receiving->initiate($this->manager, $order);
    $first->lines()->firstOrFail()->update(['quantity' => 7]);
    $this->operations->markReady($first->refresh(), $this->manager);
    $this->operations->complete($first->refresh(), $this->manager);

    $second = $this->receiving->initiate($this->manager, $order->refresh());
    $second->lines()->firstOrFail()->update(['quantity' => 4]);
    $this->operations->markReady($second->refresh(), $this->manager);

    expect(fn () => $this->operations->complete($second->refresh(), $this->manager))
        ->toThrow(OverReceiptRejected::class);

    // The first receipt stands; only the illegitimate second one was refused.
    expect((float) $order->refresh()->lines()->firstOrFail()->quantity_received)->toBe(7.0);
});

it('accepts a receipt that fills the line exactly', function (): void {
    // The boundary case the comparison has to get right: 10 of 10 is not
    // over-receipt, and float noise must not make it look like one.
    $order = orderForOverReceipt(10);

    $operation = $this->receiving->initiate($this->manager, $order);
    $this->operations->markReady($operation, $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Received);
});

it('accepts a fractional receipt that fills the line exactly across three parts', function (): void {
    // 3.333 + 3.333 + 3.334 = 10.000 exactly in thousandths, but not in binary
    // floating point. Comparing in minor units is what keeps the last receipt
    // from being rejected.
    $order = orderForOverReceipt(10);

    foreach ([3.333, 3.333, 3.334] as $quantity) {
        $operation = $this->receiving->initiate($this->manager, $order->refresh());
        $operation->lines()->firstOrFail()->update(['quantity' => $quantity]);
        $this->operations->markReady($operation->refresh(), $this->manager);
        $this->operations->complete($operation->refresh(), $this->manager);
    }

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and((float) $order->lines()->firstOrFail()->quantity_received)->toBe(10.0);
});

it('does not double-count when the same operation is completed once, whatever the listener wiring', function (): void {
    // A regression guard: the listener was briefly bound twice — once explicitly
    // and once by Laravel's auto-discovery of app/Listeners — which applied every
    // received quantity twice and made a legitimate exact-fill receipt look like
    // over-receipt.
    $order = orderForOverReceipt(10);

    $operation = $this->receiving->initiate($this->manager, $order);
    $operation->lines()->firstOrFail()->update(['quantity' => 5]);
    $this->operations->markReady($operation->refresh(), $this->manager);
    $this->operations->complete($operation->refresh(), $this->manager);

    expect((float) $order->refresh()->lines()->firstOrFail()->quantity_received)->toBe(5.0);
});
