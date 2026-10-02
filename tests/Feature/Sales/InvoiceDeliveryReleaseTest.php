<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryLink;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    $this->actor = User::factory()->create();
});

/**
 * @return array{delivery: InventoryOperation, order: Order, orderLine: OrderLine}
 */
function releasableDelivery(CustomerProfile $customer, float $quantity = 2.0, float $unitPrice = 10.0, ?Order $order = null): array
{
    $variant = ProductVariant::factory()->create();
    $order ??= Order::factory()->create(['customer_id' => $customer->getKey()]);
    $orderLine = OrderLine::factory()->create([
        'order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'tax_amount' => 0,
        'line_total' => round($quantity * $unitPrice, 2),
    ]);
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
        'customer_id' => $customer->getKey(),
        'completed_at' => now(),
    ]);
    $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'order_line_id' => $orderLine->getKey(),
        'quantity' => $quantity,
        'unit_id' => $variant->unit_id,
    ]);

    return ['delivery' => $delivery->fresh(), 'order' => $order, 'orderLine' => $orderLine->fresh()];
}

it('releases the delivery when its draft invoice is deleted so it can be invoiced again', function (): void {
    $customer = CustomerProfile::factory()->create();
    $built = releasableDelivery($customer);
    $delivery = $built['delivery'];

    $draft = app(InvoiceService::class)->createFromDelivery($this->actor, $delivery);

    expect($draft->inventory_operation_id)->toBe($delivery->getKey())
        ->and($delivery->fresh()->isInvoiced())->toBeTrue();

    $draft->delete();

    $reportedIds = collect(app(SalesReportService::class)->deliveredNotInvoiced(CarbonImmutable::now())['deliveries'])
        ->pluck('inventory_operation_id')
        ->all();

    expect(Invoice::withTrashed()->findOrFail($draft->getKey())->inventory_operation_id)->toBeNull()
        ->and($draft->inventory_operation_id)->toBeNull()
        ->and(InvoiceDeliveryLink::query()->where('inventory_operation_id', $delivery->getKey())->exists())->toBeFalse()
        ->and($delivery->fresh()->isInvoiced())->toBeFalse()
        ->and($reportedIds)->toContain($delivery->getKey());

    $reinvoiced = app(InvoiceService::class)->createFromDelivery($this->actor, $delivery->fresh());

    expect($reinvoiced->getKey())->not->toBe($draft->getKey())
        ->and($delivery->fresh()->isInvoiced())->toBeTrue();
});

it('releases every delivery of a consolidated draft that has no single-delivery pointer', function (): void {
    $customer = CustomerProfile::factory()->create();
    $order = Order::factory()->create(['customer_id' => $customer->getKey()]);
    $first = releasableDelivery($customer, order: $order)['delivery'];
    $second = releasableDelivery($customer, order: $order)['delivery'];

    $draft = app(InvoiceService::class)->createFromDeliveries($this->actor, collect([$first, $second]));
    expect($draft->inventory_operation_id)->toBeNull();

    $draft->delete();

    expect(InvoiceDeliveryLink::query()->count())->toBe(0)
        ->and($first->fresh()->isInvoiced())->toBeFalse()
        ->and($second->fresh()->isInvoiced())->toBeFalse();
});

it('keeps the delivery links when an issued invoice refuses deletion', function (): void {
    $customer = CustomerProfile::factory()->create();
    $delivery = releasableDelivery($customer)['delivery'];

    $invoice = app(InvoiceService::class)->createFromDelivery($this->actor, $delivery);
    $invoice->forceFill(['issued_at' => now()])->save();

    expect(fn () => $invoice->delete())->toThrow(DomainException::class, 'An issued invoice cannot be deleted.')
        ->and($delivery->fresh()->isInvoiced())->toBeTrue()
        ->and($invoice->fresh()->inventory_operation_id)->toBe($delivery->getKey());
});
