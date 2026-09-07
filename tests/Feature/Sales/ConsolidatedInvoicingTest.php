<?php

declare(strict_types=1);

use App\Enums\OperationStage;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryLink;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    $this->actor = User::factory()->create();
});

/**
 * Builds a completed delivery, with its own order and order line, ready to be consolidated.
 *
 * @return array{delivery: InventoryOperation, order: Order, orderLine: OrderLine}
 */
function consolidatableDelivery(
    CustomerProfile $customer,
    ProductVariant $variant,
    float $quantity,
    float $unitPrice,
    float $taxAmount = 0.0,
): array {
    $order = Order::factory()->create(['customer_id' => $customer->getKey()]);

    $orderLine = OrderLine::factory()->create([
        'order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'tax_amount' => $taxAmount,
        'line_total' => round($quantity * $unitPrice + $taxAmount, 2),
    ]);

    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
        'customer_id' => $customer->getKey(),
    ]);

    $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'order_line_id' => $orderLine->getKey(),
        'quantity' => $quantity,
        'unit_id' => $variant->unit_id,
    ]);

    return ['delivery' => $delivery->fresh(), 'order' => $order, 'orderLine' => $orderLine->fresh()];
}

it('consolidates three deliveries for one customer into one invoice with aggregated lines and three links', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    $first = consolidatableDelivery($customer, $variant, 2.0, 10.0, 1.00);
    $second = consolidatableDelivery($customer, $variant, 3.0, 10.0, 1.50);
    $third = consolidatableDelivery($customer, $variant, 1.0, 10.0, 0.50);

    $invoice = app(InvoiceService::class)->createFromDeliveries($this->actor, collect([
        $first['delivery'], $second['delivery'], $third['delivery'],
    ]));

    $invoice->refresh()->load('lines');

    expect(Invoice::query()->count())->toBe(1)
        ->and($invoice->lines)->toHaveCount(1)
        ->and((float) $invoice->lines->first()->quantity)->toBe(6.0)
        ->and((float) $invoice->subtotal)->toBe(60.0)
        ->and((float) $invoice->tax_total)->toBe(3.0)
        ->and((float) $invoice->total_amount)->toBe(63.0)
        ->and(InvoiceDeliveryLink::query()->where('invoice_id', $invoice->getKey())->count())->toBe(3)
        ->and(InvoiceDeliveryLink::query()->pluck('inventory_operation_id')->sort()->values()->all())
        ->toBe(collect([$first['delivery'], $second['delivery'], $third['delivery']])
            ->map(fn (InventoryOperation $delivery): int => (int) $delivery->getKey())
            ->sort()->values()->all());
});

it('refuses to consolidate a delivery already linked to another invoice', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    $alreadyInvoiced = consolidatableDelivery($customer, $variant, 1.0, 10.0);
    app(InvoiceService::class)->createFromDelivery($this->actor, $alreadyInvoiced['delivery']);

    $fresh = consolidatableDelivery($customer, $variant, 1.0, 10.0);

    expect(fn () => app(InvoiceService::class)->createFromDeliveries($this->actor, collect([
        $alreadyInvoiced['delivery']->fresh(), $fresh['delivery'],
    ])))->toThrow(DomainException::class, 'This delivery has already been invoiced.');

    expect(Invoice::query()->count())->toBe(1);
});

it('refuses to consolidate deliveries for different customers', function (): void {
    $customerA = CustomerProfile::factory()->create();
    $customerB = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    $deliveryA = consolidatableDelivery($customerA, $variant, 1.0, 10.0);
    $deliveryB = consolidatableDelivery($customerB, $variant, 1.0, 10.0);

    expect(fn () => app(InvoiceService::class)->createFromDeliveries($this->actor, collect([
        $deliveryA['delivery'], $deliveryB['delivery'],
    ])))->toThrow(DomainException::class, 'Consolidated invoicing requires every delivery to share one customer.');

    expect(Invoice::query()->count())->toBe(0);
});

it('refuses to consolidate a delivery that is not yet completed', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    $done = consolidatableDelivery($customer, $variant, 1.0, 10.0);
    $notDone = InventoryOperation::factory()->delivery()->ready()->create([
        'customer_id' => $customer->getKey(),
        'source_document_type' => Order::class,
        'source_document_id' => $done['order']->getKey(),
    ]);

    expect($notDone->stage)->not->toBe(OperationStage::Done);

    expect(fn () => app(InvoiceService::class)->createFromDeliveries($this->actor, collect([
        $done['delivery'], $notDone,
    ])))->toThrow(DomainException::class, 'Only a completed delivery can be invoiced.');

    expect(Invoice::query()->count())->toBe(0);
});

it('allows exactly one of two simultaneous consolidations sharing a delivery to succeed', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    $shared = consolidatableDelivery($customer, $variant, 1.0, 10.0);

    // Stands in for the invoice a competing, already-committed consolidation created for the
    // same delivery, so the injected raw insert below satisfies invoice_delivery_links'
    // foreign key exactly as a real competing transaction's committed invoice would.
    $competitorInvoice = Invoice::factory()->create(['customer_id' => $customer->getKey()]);

    $injected = false;
    $sharedDeliveryId = (int) $shared['delivery']->getKey();

    InvoiceDeliveryLink::creating(function (InvoiceDeliveryLink $link) use (&$injected, $sharedDeliveryId, $competitorInvoice): void {
        if ($injected || (int) $link->inventory_operation_id !== $sharedDeliveryId) {
            return;
        }

        $injected = true;

        // Simulates a competing consolidation that wins the race and commits its link to this
        // same delivery after this service's friendly pre-check but before this insert reaches
        // the database unique key. Raw SQL deliberately bypasses the model.
        DB::table('invoice_delivery_links')->insert([
            'invoice_id' => $competitorInvoice->getKey(),
            'inventory_operation_id' => $sharedDeliveryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn () => app(InvoiceService::class)->createFromDeliveries($this->actor, collect([$shared['delivery']])))
        ->toThrow(DomainException::class, 'This delivery has already been invoiced.');

    // The injected competing insert and this attempt's own invoice/lines were both inside the
    // one failed transaction, so both vanish on rollback — exactly one consolidation may ever
    // commit for this delivery, and this one lost the race and left no partial state behind.
    expect($injected)->toBeTrue()
        ->and(InvoiceDeliveryLink::query()->where('inventory_operation_id', $sharedDeliveryId)->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(Invoice::query()->sole()->getKey())->toBe($competitorInvoice->getKey());
});

it('produces consolidated totals equal to the sum of three individually invoiced equivalents', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variantA = ProductVariant::factory()->create();
    $variantB = ProductVariant::factory()->create();
    $variantC = ProductVariant::factory()->create();

    // One set invoiced individually, one line item apiece.
    $individualA = consolidatableDelivery($customer, $variantA, 2.0, 15.0, 1.20);
    $individualB = consolidatableDelivery($customer, $variantB, 1.5, 20.0, 2.10);
    $individualC = consolidatableDelivery($customer, $variantC, 4.0, 5.0, 0.80);

    $invoiceA = app(InvoiceService::class)->createFromDelivery($this->actor, $individualA['delivery']);
    $invoiceB = app(InvoiceService::class)->createFromDelivery($this->actor, $individualB['delivery']);
    $invoiceC = app(InvoiceService::class)->createFromDelivery($this->actor, $individualC['delivery']);

    $expectedSubtotal = round((float) $invoiceA->subtotal + (float) $invoiceB->subtotal + (float) $invoiceC->subtotal, 2);
    $expectedTax = round((float) $invoiceA->tax_total + (float) $invoiceB->tax_total + (float) $invoiceC->tax_total, 2);
    $expectedTotal = round((float) $invoiceA->total_amount + (float) $invoiceB->total_amount + (float) $invoiceC->total_amount, 2);

    // An equivalent, independent set of deliveries consolidated onto a single invoice.
    $consolidatedA = consolidatableDelivery($customer, $variantA, 2.0, 15.0, 1.20);
    $consolidatedB = consolidatableDelivery($customer, $variantB, 1.5, 20.0, 2.10);
    $consolidatedC = consolidatableDelivery($customer, $variantC, 4.0, 5.0, 0.80);

    $consolidatedInvoice = app(InvoiceService::class)->createFromDeliveries($this->actor, collect([
        $consolidatedA['delivery'], $consolidatedB['delivery'], $consolidatedC['delivery'],
    ]));

    expect((float) $consolidatedInvoice->subtotal)->toBe($expectedSubtotal)
        ->and((float) $consolidatedInvoice->tax_total)->toBe($expectedTax)
        ->and((float) $consolidatedInvoice->total_amount)->toBe($expectedTotal)
        ->and($consolidatedInvoice->lines)->toHaveCount(3);
});
