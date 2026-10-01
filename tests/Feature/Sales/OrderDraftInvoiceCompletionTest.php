<?php

declare(strict_types=1);

use App\Enums\OperationStage;
use App\Enums\OrderStatus;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Services\Sales\OrderCompletionEligibilityService;
use App\Services\Sales\OrderCompletionService;
use App\Services\Sales\OrderFulfillmentQuantityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A fully delivered and arrived order of two units, with one unit billed on an issued, fully
 * paid invoice. The second unit is the quantity each test bills (or doesn't) on top.
 *
 * @return array{order: Order, line: OrderLine}
 */
function deliveredOrderWithOneUnitInvoiced(): array
{
    $variant = ProductVariant::factory()->create();
    $order = Order::factory()->create(['auto_close_due_at' => now()->subMinute()]);
    $line = OrderLine::factory()->create([
        'order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'quantity' => '2.000000',
        'unit_id' => $variant->unit_id,
    ]);

    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
        'customer_id' => $order->customer_id,
    ]);
    $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'order_line_id' => $line->getKey(),
        'quantity' => '2.000000',
        'base_quantity' => '2.000000',
        'unit_id' => $variant->unit_id,
    ]);
    expect($delivery->stage)->toBe(OperationStage::Done);

    Shipment::factory()->arrived()->create([
        'order_id' => $order->getKey(),
        'inventory_operation_id' => $delivery->getKey(),
        'warehouse_id' => $delivery->source_warehouse_id,
    ]);

    $paid = Invoice::factory()->create([
        'order_id' => $order->getKey(),
        'customer_id' => $order->customer_id,
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => '10.00',
        'tax_total' => '0.00',
        'total_amount' => '10.00',
        'amount_paid' => '10.00',
    ]);
    InvoiceLine::factory()->create([
        'invoice_id' => $paid->getKey(),
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'description' => 'Billed unit',
        'quantity' => '1.000000',
        'unit_price' => '10.00',
        'tax_amount' => '0.00',
        'line_total' => '10.00',
    ]);

    return ['order' => $order->refresh(), 'line' => $line->refresh()];
}

function draftInvoiceForUnit(Order $order, OrderLine $line, string $status = 'draft'): Invoice
{
    $draft = Invoice::factory()->create([
        'order_id' => $order->getKey(),
        'customer_id' => $order->customer_id,
        'status' => $status,
        'issued_at' => null,
        'subtotal' => '10.00',
        'tax_total' => '0.00',
        'total_amount' => '10.00',
    ]);
    InvoiceLine::factory()->create([
        'invoice_id' => $draft->getKey(),
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $line->product_variant_id,
        'description' => 'Billed unit',
        'quantity' => '1.000000',
        'unit_price' => '10.00',
        'tax_amount' => '0.00',
        'line_total' => '10.00',
    ]);

    return $draft;
}

it('does not count a draft invoice line as invoiced quantity', function (): void {
    ['order' => $order, 'line' => $line] = deliveredOrderWithOneUnitInvoiced();
    draftInvoiceForUnit($order, $line);

    $progress = app(OrderFulfillmentQuantityService::class)->forOrder($order)->sole();

    expect($progress->invoicedBase)->toBe(1.0);
});

it('does not count a cancelled invoice line as invoiced quantity', function (): void {
    ['order' => $order, 'line' => $line] = deliveredOrderWithOneUnitInvoiced();
    draftInvoiceForUnit($order, $line, 'cancelled');

    expect(app(OrderFulfillmentQuantityService::class)->forOrder($order)->sole()->invoicedBase)->toBe(1.0);
});

it('blocks completion with invoice_draft while a line-bearing draft invoice exists', function (): void {
    ['order' => $order, 'line' => $line] = deliveredOrderWithOneUnitInvoiced();
    draftInvoiceForUnit($order, $line);

    $result = app(OrderCompletionEligibilityService::class)->evaluate($order);

    expect($result->eligible)->toBeFalse()
        ->and($result->fullyInvoiced)->toBeFalse()
        ->and(collect($result->blockers)->pluck('code')->all())->toBe(['invoice_draft']);
});

it('keeps the order open when the scheduler runs while a draft invoice is unissued', function (): void {
    ['order' => $order, 'line' => $line] = deliveredOrderWithOneUnitInvoiced();
    draftInvoiceForUnit($order, $line);

    $result = app(OrderCompletionService::class)->closeAutomatically($order);

    expect($result->status)->toBe(OrderStatus::Released);
});

it('still blocks on invoice_draft when issued invoices already cover the whole order', function (): void {
    ['order' => $order, 'line' => $line] = deliveredOrderWithOneUnitInvoiced();
    $second = Invoice::factory()->create([
        'order_id' => $order->getKey(),
        'customer_id' => $order->customer_id,
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => '10.00',
        'tax_total' => '0.00',
        'total_amount' => '10.00',
        'amount_paid' => '10.00',
    ]);
    InvoiceLine::factory()->create([
        'invoice_id' => $second->getKey(),
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $line->product_variant_id,
        'description' => 'Billed unit',
        'quantity' => '1.000000',
        'unit_price' => '10.00',
        'tax_amount' => '0.00',
        'line_total' => '10.00',
    ]);
    draftInvoiceForUnit($order, $line);

    $service = app(OrderCompletionEligibilityService::class);
    $blocked = $service->evaluate($order);

    expect($blocked->fullyInvoiced)->toBeTrue()
        ->and($blocked->eligible)->toBeFalse()
        ->and(collect($blocked->blockers)->pluck('code')->all())->toBe(['invoice_draft']);

    Invoice::query()->where('status', 'draft')->sole()->delete();

    expect($service->evaluate($order)->eligible)->toBeTrue();
});

it('reports invoice_missing and not invoice_draft when the delivered unit has no invoice at all', function (): void {
    ['order' => $order] = deliveredOrderWithOneUnitInvoiced();

    expect(collect(app(OrderCompletionEligibilityService::class)->evaluate($order)->blockers)->pluck('code')->all())
        ->toBe(['invoice_missing']);
});
