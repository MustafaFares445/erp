<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Jobs\SendInvoiceEmail;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PaymentTerm;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function invoiceCoverageInvoke(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(InvoiceService::class, $method)
        ->invoke(app(InvoiceService::class), ...$arguments);
}
beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('covers scalar invoice value validation helpers', function (): void {
    expect(invoiceCoverageInvoke('integerValue', '42'))->toBe(42)
        ->and(invoiceCoverageInvoke('decimalValue', '2.5'))->toBe(2.5)
        ->and(invoiceCoverageInvoke('textValue', 12.5))->toBe('12.5')
        ->and(fn (): mixed => invoiceCoverageInvoke('integerValue', 'nope'))
        ->toThrow(DomainException::class, 'integer invoice value')
        ->and(fn (): mixed => invoiceCoverageInvoke('decimalValue', []))
        ->toThrow(DomainException::class, 'numeric invoice value')
        ->and(fn (): mixed => invoiceCoverageInvoke('textValue', []))
        ->toThrow(DomainException::class, 'scalar invoice text');
});
it('covers standalone invoice validation branches', function (): void {
    $service = app(InvoiceService::class);
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();

    expect(fn () => $service->createStandalone($actor, ['customer_id' => 999999], [
        ['description' => 'Service', 'quantity' => 1, 'unit_price' => 10, 'tax_amount' => 0],
    ]))->toThrow(DomainException::class, 'valid customer');

    expect(fn () => $service->createStandalone($actor, ['customer_id' => $customer->getKey()], [
        ['description' => 'Bad qty', 'quantity' => 0, 'unit_price' => 10, 'tax_amount' => 0],
    ]))->toThrow(DomainException::class, 'positive quantity');

    expect(fn () => $service->createStandalone($actor, ['customer_id' => $customer->getKey()], [
        ['product_variant_id' => 999999, 'quantity' => 1, 'unit_price' => 10, 'tax_amount' => 0],
    ]))->toThrow(DomainException::class, 'valid product variant');

    $variant = ProductVariant::factory()->create();
    expect(fn () => $service->createStandalone($actor, ['customer_id' => $customer->getKey()], [
        ['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => -1, 'tax_amount' => 0],
    ]))->toThrow(DomainException::class, 'unit price must be non-negative');
});
it('covers standalone service price guards and scalar descriptions', function (): void {
    $service = app(InvoiceService::class);
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();

    expect(fn () => $service->createStandalone($actor, ['customer_id' => $customer->getKey()], [
        ['description' => 'Missing price', 'quantity' => 1, 'tax_amount' => 0],
    ]))->toThrow(DomainException::class, 'explicit unit price')
        ->and(fn () => $service->createStandalone($actor, ['customer_id' => $customer->getKey()], [
            ['description' => 'Negative price', 'quantity' => 1, 'unit_price' => -5, 'tax_amount' => 0],
        ]))->toThrow(DomainException::class, 'unit price must be non-negative');

    $invoice = $service->createStandalone($actor, [
        'customer_id' => $customer->getKey(),
        'invoice_date' => 20260918,
    ], [
        ['description' => 123, 'quantity' => '2', 'unit_price' => '5.5', 'tax_amount' => '1'],
    ]);

    expect((float) $invoice->total_amount)->toBe(12.0);
});
it('covers invoice issue empty and nonpositive totals', function (): void {
    $service = app(InvoiceService::class);
    $actor = User::factory()->create();

    $empty = Invoice::factory()->create(['status' => InvoiceStatus::Draft]);
    expect(fn () => $service->issue($actor, $empty))
        ->toThrow(DomainException::class, 'at least one line before issue');

    $zero = Invoice::factory()->create(['status' => InvoiceStatus::Draft]);
    InvoiceLine::factory()->create([
        'invoice_id' => $zero->getKey(),
        'description' => 'Zero total coverage line',
        'quantity' => 1,
        'unit_price' => 0,
        'tax_amount' => 0,
        'line_total' => 0,
    ]);
    expect(fn () => $service->issue($actor, $zero))
        ->toThrow(DomainException::class, 'positive total');
});
it('covers delivery customer mismatch and missing order aggregation guards', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => CustomerProfile::factory()->create()->getKey(),
    ]);

    expect(fn (): mixed => invoiceCoverageInvoke('lockAndValidateDeliveries', [(int) $delivery->getKey()], 999999))
        ->toThrow(DomainException::class, 'belong to the invoice customer');

    $detachedDelivery = new InventoryOperation;
    $detachedDelivery->setRelation('sourceDocument', null);
    $detachedDelivery->setRelation('lines', new EloquentCollection);

    expect(fn (): mixed => invoiceCoverageInvoke(
        'aggregateDeliveries',
        new EloquentCollection([$detachedDelivery]),
        new EloquentCollection,
    ))->toThrow(DomainException::class, 'originating sales order');
});
it('covers ambiguous missing price and missing variant delivery aggregation guards', function (): void {
    $deliveryLine = new InventoryOperationLine;
    $deliveryLine->forceFill(['product_variant_id' => 77, 'quantity' => '1']);
    $deliveryLine->setRelation('orderLine', null);

    $delivery = new InventoryOperation;
    $delivery->setRelation('lines', new EloquentCollection([$deliveryLine]));

    $order = new Order;
    $order->setRelation('lines', new EloquentCollection);

    expect(fn (): mixed => invoiceCoverageInvoke('aggregateDeliveredLines', $delivery, $order))
        ->toThrow(DomainException::class, 'cannot be priced unambiguously');

    $orderLine = new OrderLine;
    $orderLine->forceFill(['id' => 1, 'product_variant_id' => 77, 'unit_price' => null]);

    $order->setRelation('lines', new EloquentCollection([$orderLine]));

    expect(fn (): mixed => invoiceCoverageInvoke('aggregateDeliveredLines', $delivery, $order))
        ->toThrow(DomainException::class, 'commercial unit price');

    $orderLine->forceFill(['unit_price' => '10.00']);
    $orderLine->setRelation('productVariant', null);

    expect(fn (): mixed => invoiceCoverageInvoke('aggregateDeliveredLines', $delivery, $order))
        ->toThrow(DomainException::class, 'requires a product variant');
});

it('covers automatic product pricing payment terms and standalone delivery linking', function (): void {
    $service = app(InvoiceService::class);
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $term = PaymentTerm::factory()->create([
        'name' => 'Coverage Net 7',
        'due_days' => 7,
    ]);
    $variant = ProductVariant::factory()->create([
        'base_price' => '25.00',
        'min_price' => null,
    ]);
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $customer->getKey(),
    ]);

    $invoice = $service->createStandalone(
        $actor,
        [
            'customer_id' => $customer->getKey(),
            'payment_term_id' => (string) $term->getKey(),
            'invoice_date' => '2026-09-18',
        ],
        [[
            'product_variant_id' => $variant->getKey(),
            'quantity' => 2,
            'tax_amount' => 0,
        ]],
        new EloquentCollection([$delivery]),
    );

    expect((float) $invoice->lines->sole()->unit_price)->toBe(25.0)
        ->and($invoice->payment_term_id)->toBe($term->getKey())
        ->and($invoice->due_date?->toDateString())->toBe('2026-09-25')
        ->and($invoice->inventory_operation_id)->toBe($delivery->getKey())
        ->and($invoice->deliveryLinks)->toHaveCount(1);
});

it('requires delivery source orders when creating from deliveries', function (): void {
    $customer = CustomerProfile::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $customer->getKey(),
        'source_document_type' => null,
        'source_document_id' => null,
    ]);

    expect(fn () => app(InvoiceService::class)->createFromDeliveries(
        User::factory()->create(),
        new EloquentCollection([$delivery]),
    ))->toThrow(DomainException::class, 'originating sales order');
});

it('derives a due date from the source order payment term', function (): void {
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $term = PaymentTerm::factory()->create([
        'name' => 'Coverage Net 11',
        'due_days' => 11,
    ]);
    $variant = ProductVariant::factory()->create();
    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'payment_term_id' => $term->getKey(),
    ]);
    $orderLine = OrderLine::factory()->create([
        'order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => 1,
        'unit_price' => 10,
        'tax_amount' => 0,
        'line_total' => 10,
    ]);
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $customer->getKey(),
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
    ]);
    $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'order_line_id' => $orderLine->getKey(),
        'quantity' => 1,
        'unit_id' => $variant->unit_id,
    ]);

    $invoice = app(InvoiceService::class)->createFromDeliveries(
        $actor,
        new EloquentCollection([$delivery]),
    );

    expect($invoice->payment_term_id)->toBe($term->getKey())
        ->and($invoice->due_date)->not->toBeNull();
});

it('covers invoice send status email and queued send paths', function (): void {
    $service = app(InvoiceService::class);
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();

    $draft = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => InvoiceStatus::Draft,
    ]);
    expect(fn () => $service->send($actor, $draft))
        ->toThrow(DomainException::class, 'Only an issued or sent invoice');

    $invalidCustomer = CustomerProfile::factory()->create(['email' => 'not-an-email']);
    $invalid = Invoice::factory()->create([
        'customer_id' => $invalidCustomer->getKey(),
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
    ]);
    $invalidPath = storage_path('logs/invoice-invalid-email-coverage.pdf');
    file_put_contents($invalidPath, '%PDF-1.4 coverage');
    $invalid->addMedia($invalidPath)->toMediaCollection('invoice-pdf');

    expect(fn () => $service->send($actor, $invalid->refresh()))
        ->toThrow(DomainException::class, 'valid email address');

    Queue::fake();
    $valid = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
    ]);
    $validPath = storage_path('logs/invoice-send-coverage.pdf');
    file_put_contents($validPath, '%PDF-1.4 coverage');
    $valid->addMedia($validPath)->toMediaCollection('invoice-pdf');

    $sent = $service->send($actor, $valid->refresh());

    expect($sent->getKey())->toBe($valid->getKey());
    Queue::assertPushed(SendInvoiceEmail::class);
});
