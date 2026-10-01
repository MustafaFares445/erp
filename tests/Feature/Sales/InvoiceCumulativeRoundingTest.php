<?php

declare(strict_types=1);

use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    $this->actor = User::factory()->create();
});

/**
 * One order line delivered in equal parts, one completed delivery per part.
 *
 * @return array{order: Order, line: OrderLine, deliveries: list<InventoryOperation>}
 */
function orderDeliveredInParts(string $orderedQuantity, string $unitPrice, string $tax, string $partQuantity, int $parts): array
{
    $variant = ProductVariant::factory()->create();
    $order = Order::factory()->create();
    $line = OrderLine::factory()->create([
        'order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => $orderedQuantity,
        'unit_price' => $unitPrice,
        'tax_amount' => $tax,
        'line_total' => round((float) $orderedQuantity * (float) $unitPrice + (float) $tax, 2),
    ]);

    $deliveries = [];

    for ($part = 0; $part < $parts; $part++) {
        $delivery = InventoryOperation::factory()->delivery()->done()->create([
            'source_document_type' => Order::class,
            'source_document_id' => $order->getKey(),
            'customer_id' => $order->customer_id,
        ]);
        $delivery->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'order_line_id' => $line->getKey(),
            'quantity' => $partQuantity,
            'base_quantity' => $partQuantity,
            'unit_id' => $variant->unit_id,
        ]);
        $deliveries[] = $delivery->fresh();
    }

    return ['order' => $order, 'line' => $line->fresh(), 'deliveries' => $deliveries];
}

it('invoices three equal deliveries of a 10.00 tax line so the shares sum to exactly 10.00', function (): void {
    ['deliveries' => $deliveries] = orderDeliveredInParts('3.000', '10.00', '10.00', '1.000', 3);
    $service = app(InvoiceService::class);

    $taxShares = array_map(
        fn (InventoryOperation $delivery): string => $service->createFromDelivery($this->actor, $delivery)->lines()->sole()->tax_amount,
        $deliveries,
    );

    expect($taxShares)->toBe(['3.33', '3.34', '3.33'])
        ->and(round(array_sum(array_map(floatval(...), $taxShares)), 2))->toBe(10.0)
        ->and((float) Invoice::query()->sum('tax_total'))->toBe(10.0)
        ->and((float) Invoice::query()->sum('subtotal'))->toBe(30.0);
});

it('keeps the order line tax exact when the three deliveries are consolidated on one invoice', function (): void {
    ['deliveries' => $deliveries] = orderDeliveredInParts('3.000', '10.00', '10.00', '1.000', 3);

    $invoice = app(InvoiceService::class)->createFromDeliveries($this->actor, collect($deliveries));

    expect((float) $invoice->tax_total)->toBe(10.0)
        ->and((float) $invoice->total_amount)->toBe(40.0);
});

it('counts a deleted draft as not invoiced when sharing the remaining tax', function (): void {
    ['deliveries' => $deliveries] = orderDeliveredInParts('3.000', '10.00', '10.00', '1.000', 3);
    $service = app(InvoiceService::class);

    $service->createFromDelivery($this->actor, $deliveries[0]);

    $abandoned = $service->createFromDelivery($this->actor, $deliveries[1]);
    $abandoned->delete();

    $third = $service->createFromDelivery($this->actor, $deliveries[2]);
    $second = $service->createFromDelivery($this->actor, $deliveries[1]->fresh());

    expect($third->lines()->sole()->tax_amount)->toBe('3.34')
        ->and($second->lines()->sole()->tax_amount)->toBe('3.33')
        ->and((float) Invoice::query()->sum('tax_total'))->toBe(10.0);
});

it('does not lose a cent of net amount when fractional deliveries each round up', function (): void {
    ['deliveries' => $deliveries] = orderDeliveredInParts('1.500', '0.05', '0.00', '0.500', 3);
    $service = app(InvoiceService::class);

    $nets = array_map(
        fn (InventoryOperation $delivery): float => (float) $service->createFromDelivery($this->actor, $delivery)->subtotal,
        $deliveries,
    );

    expect(round(array_sum($nets), 2))->toBe(0.08);
});
