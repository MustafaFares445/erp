<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionSettlementState;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Warehouse;
use App\Services\Orders\WarehouseStockService;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotReceivable;
use App\Services\Purchasing\SupplierCostWritebackService;
use App\Services\Sales\OrderWorkflowService;
use App\Services\Sales\SalesDashboardLinks;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers warehouse stock candidate and availability projections', function (): void {
    $variant = ProductVariant::factory()->create();
    $first = Warehouse::factory()->create(['name' => 'Alpha Warehouse']);
    $second = Warehouse::factory()->create(['name' => 'Beta Warehouse']);
    $inactive = Warehouse::factory()->inactive()->create(['name' => 'Inactive Warehouse']);

    InventoryStock::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $first->getKey(),
        'on_hand_quantity' => '8.000000',
        'reserved_quantity' => '2.000000',
        'available_quantity' => '6.000000',
    ]);
    InventoryStock::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $second->getKey(),
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '1.000000',
        'available_quantity' => '4.000000',
    ]);
    InventoryStock::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $inactive->getKey(),
        'on_hand_quantity' => '9.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '9.000000',
    ]);

    $service = app(WarehouseStockService::class);
    $candidates = $service->eligibleCandidates([$variant->getKey()]);
    $availability = $service->availability($variant->getKey());

    expect(array_keys($candidates))->toBe([$first->getKey(), $second->getKey()])
        ->and($candidates[$first->getKey()]['stocks'][$variant->getKey()])->toBe(6.0)
        ->and($availability['available_quantity'])->toBe(10.0)
        ->and($availability['warehouses'])->toHaveCount(2)
        ->and(array_column($availability['warehouses'], 'name'))->toBe(['Alpha Warehouse', 'Beta Warehouse']);
});

it('covers purchase-order-not-receivable exception constructors', function (): void {
    $order = new PurchaseOrder;
    $order->forceFill([
        'purchase_order_number' => 'PO-COV-038',
        'status' => PurchaseOrderStatus::Draft,
    ]);
    $warehouse = new Warehouse;
    $warehouse->forceFill(['name' => 'Coverage Warehouse']);

    expect(PurchaseOrderNotReceivable::status($order)->getMessage())->toContain('PO-COV-038')
        ->and(PurchaseOrderNotReceivable::notSent($order)->getMessage())->toContain('PO-COV-038')
        ->and(PurchaseOrderNotReceivable::inactiveWarehouse($warehouse)->getMessage())->toContain('Coverage Warehouse');
});

it('skips supplier cost writeback when the conversion factor is zero', function (): void {
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();
    $order = PurchaseOrder::factory()->create(['supplier_id' => $supplier->getKey()]);

    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '1.000',
        'unit_cost' => '12.00',
        'line_total' => '12.00',
    ]);
    $line->forceFill(['conversion_factor_snapshot' => '0.000000'])->saveQuietly();

    app(SupplierCostWritebackService::class)->apply($order->refresh()->load('lines.productVariant'));

    expect(SupplierProductReference::query()
        ->where('supplier_id', $supplier->getKey())
        ->where('product_variant_id', $variant->getKey())
        ->exists())->toBeFalse();
});

it('covers every provider payment settlement fallback state', function (): void {
    $method = new ReflectionMethod(PaymentTransaction::class, 'stateFromProviderStatus');

    expect($method->invoke(null, PaymentTransactionStatus::Failed))->toBe(PaymentTransactionSettlementState::Failed)
        ->and($method->invoke(null, PaymentTransactionStatus::Cancelled))->toBe(PaymentTransactionSettlementState::Cancelled)
        ->and($method->invoke(null, PaymentTransactionStatus::Pending))->toBe(PaymentTransactionSettlementState::WaitingForProvider)
        ->and($method->invoke(null, PaymentTransactionStatus::RequiresAction))->toBe(PaymentTransactionSettlementState::WaitingForProvider)
        ->and($method->invoke(null, PaymentTransactionStatus::Succeeded))->toBe(PaymentTransactionSettlementState::RequiresAttention)
        ->and($method->invoke(null, PaymentTransactionStatus::PartiallyRefunded))->toBe(PaymentTransactionSettlementState::RequiresAttention)
        ->and($method->invoke(null, PaymentTransactionStatus::Refunded))->toBe(PaymentTransactionSettlementState::RequiresAttention);
});

it('covers sales dashboard link parameter combinations', function (): void {
    $params = new ReflectionMethod(SalesDashboardLinks::class, 'params');

    expect($params->invoke(null))->toBe([])
        ->and($params->invoke(null, 'open'))->toBe(['tab' => 'open'])
        ->and($params->invoke(null, null, 7, 9))->toBe([
            'filters' => [
                'customer_id' => ['value' => 7],
                'employee_id' => ['value' => 9],
            ],
        ]);
});

it('covers order workflow milestones, blockers, and immutable timestamp conversion', function (): void {
    $service = app(OrderWorkflowService::class);
    $milestone = new ReflectionMethod(OrderWorkflowService::class, 'milestone');
    $blocker = new ReflectionMethod(OrderWorkflowService::class, 'blocker');
    $toImmutable = new ReflectionMethod(OrderWorkflowService::class, 'toImmutable');

    $order = new Order;

    $order->forceFill(['status' => OrderStatus::Cancelled->value]);
    expect($milestone->invoke($service, $order, []))->toBe('Cancelled');

    $order->forceFill(['status' => OrderStatus::Closed->value]);
    expect($milestone->invoke($service, $order, []))->toBe('Closed');

    $order->forceFill(['status' => OrderStatus::Draft->value]);
    expect($milestone->invoke($service, $order, []))->toBe('Draft')
        ->and($blocker->invoke($service, $order, []))->toBe([
            'commercial_not_confirmed',
            'The commercial order must be confirmed before release.',
        ]);

    $order->forceFill(['status' => OrderStatus::Confirmed->value]);
    expect($milestone->invoke($service, $order, []))->toBe('Awaiting Release')
        ->and($blocker->invoke($service, $order, []))[0]->toBe('not_released');

    $order->forceFill(['status' => OrderStatus::Released->value]);

    expect($milestone->invoke($service, $order, ['procurement_outstanding' => 1.0]))->toBe('Supply Blocked')
        ->and($blocker->invoke($service, $order, ['procurement_outstanding' => 1.0]))[0]->toBe('procurement_open')
        ->and($milestone->invoke($service, $order, ['remaining' => 1.0, 'planned' => 0.0]))->toBe('Awaiting Logistics Allocation')
        ->and($milestone->invoke($service, $order, ['remaining' => 1.0, 'planned' => 0.5]))->toBe('Partially Allocated')
        ->and($blocker->invoke($service, $order, ['remaining' => 1.0]))[0]->toBe('awaiting_logistics_allocation')
        ->and($milestone->invoke($service, $order, ['remaining' => 0.0, 'ready' => 1.0]))->toBe('Ready to Dispatch')
        ->and($blocker->invoke($service, $order, ['remaining' => 0.0, 'ready' => 1.0]))[0]->toBe('delivery_waiting_stock')
        ->and($milestone->invoke($service, $order, ['remaining' => 0.0, 'ready' => 0.0, 'dispatched' => 2.0, 'arrived' => 1.0]))->toBe('In Transit')
        ->and($blocker->invoke($service, $order, ['remaining' => 0.0, 'ready' => 0.0, 'dispatched' => 2.0, 'arrived' => 1.0]))[0]->toBe('shipment_in_transit')
        ->and($milestone->invoke($service, $order, ['remaining' => 0.0, 'ready' => 0.0, 'dispatched' => 1.0, 'arrived' => 1.0, 'fully_invoiced' => 0.0, 'draft_invoice_count' => 1.0]))->toBe('Invoice Draft')
        ->and($blocker->invoke($service, $order, ['remaining' => 0.0, 'ready' => 0.0, 'dispatched' => 1.0, 'arrived' => 1.0, 'fully_invoiced' => 0.0, 'draft_invoice_count' => 1.0]))[0]->toBe('invoice_draft')
        ->and($milestone->invoke($service, $order, ['remaining' => 0.0, 'ready' => 0.0, 'dispatched' => 1.0, 'arrived' => 1.0, 'fully_invoiced' => 0.0, 'draft_invoice_count' => 0.0]))->toBe('Invoice Pending')
        ->and($blocker->invoke($service, $order, ['remaining' => 0.0, 'ready' => 0.0, 'dispatched' => 1.0, 'arrived' => 1.0, 'fully_invoiced' => 0.0, 'draft_invoice_count' => 0.0]))[0]->toBe('invoice_pending')
        ->and($milestone->invoke($service, $order, ['fully_invoiced' => 1.0, 'financially_settled' => 0.0]))->toBe('Payment Pending')
        ->and($blocker->invoke($service, $order, ['fully_invoiced' => 1.0, 'financially_settled' => 0.0]))[0]->toBe('payment_pending')
        ->and($milestone->invoke($service, $order, ['fully_invoiced' => 1.0, 'financially_settled' => 1.0, 'auto_close_due' => 1.0]))->toBe('Auto Close Pending')
        ->and($milestone->invoke($service, $order, ['fully_invoiced' => 1.0, 'financially_settled' => 1.0, 'auto_close_due' => 0.0]))->toBe('Awaiting Customer Confirmation')
        ->and($blocker->invoke($service, $order, ['fully_invoiced' => 1.0, 'financially_settled' => 1.0]))->toBe([null, null])
        ->and($toImmutable->invoke($service, null))->toBeNull();

    $timestamp = Carbon::parse('2026-09-30 10:00:00');
    expect($toImmutable->invoke($service, $timestamp))
        ->toBeInstanceOf(CarbonImmutable::class);
});
