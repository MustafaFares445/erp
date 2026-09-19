<?php

declare(strict_types=1);

use App\Enums\InventoryReturnStatus;
use App\Enums\OperationStage;
use App\Enums\ShipmentStatus;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Services\Sales\OrderFulfillmentQuantityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fulfillmentQuantityDelivery(Order $order, OrderLine $orderLine, OperationStage $stage, string $quantity): InventoryOperation
{
    $factory = InventoryOperation::factory()->delivery();

    $factory = match ($stage) {
        OperationStage::Ready => $factory->ready(),
        OperationStage::Done => $factory->done(),
        OperationStage::Canceled => $factory->canceled(),
        default => $factory,
    };

    $delivery = $factory->create([
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
        'customer_id' => $order->customer_id,
    ]);

    $delivery->lines()->create([
        'product_variant_id' => $orderLine->product_variant_id,
        'order_line_id' => $orderLine->getKey(),
        'quantity' => $quantity,
        'base_quantity' => $quantity,
        'unit_id' => $orderLine->unit_id,
        'transaction_unit_id' => $orderLine->unit_id,
        'conversion_factor_snapshot' => '1.000000',
    ]);

    return $delivery->refresh();
}
it('summarizes ready dispatched arrived returned invoiced and remaining quantities', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    $line = OrderLine::factory()->create([
        'order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'quantity' => '10.000000',
        'unit_id' => $variant->unit_id,
        'short_closed_base_quantity' => '1.000000',
    ]);

    $line->forceFill([
        'base_quantity' => '10.000000',
        'conversion_factor_snapshot' => '1.000000',
        'short_closed_base_quantity' => '1.000000',
    ])->saveQuietly();

    $ready = fulfillmentQuantityDelivery($order, $line, OperationStage::Ready, '2.000000');
    $done = fulfillmentQuantityDelivery($order, $line, OperationStage::Done, '3.000000');
    fulfillmentQuantityDelivery($order, $line, OperationStage::Canceled, '4.000000');

    Shipment::factory()->arrived()->create([
        'order_id' => $order->getKey(),
        'inventory_operation_id' => $done->getKey(),
        'warehouse_id' => $done->source_warehouse_id,
        'status' => ShipmentStatus::Arrived,
    ]);

    $doneLine = $done->lines()->sole();
    $return = InventoryReturn::factory()->customer()->create();
    InventoryReturnLine::factory()->create([
        'inventory_return_id' => $return->getKey(),
        'product_variant_id' => $variant->getKey(),
        'original_inventory_operation_line_id' => $doneLine->getKey(),
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
        'posted_base_quantity' => '1.000000',
    ]);
    InventoryReturn::withoutEvents(function () use ($return): void {
        $return->forceFill([
            'status' => InventoryReturnStatus::Posted,
            'posted_at' => now(),
        ])->save();
    });

    $invoice = Invoice::factory()->create([
        'order_id' => $order->getKey(),
        'customer_id' => $order->customer_id,
    ]);
    InvoiceLine::factory()->create([
        'invoice_id' => $invoice->getKey(),
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'description' => 'Fulfillment quantity coverage',
        'quantity' => '4.000000',
        'unit_price' => '1.00',
        'tax_amount' => '0.00',
        'line_total' => '4.00',
    ]);

    $service = app(OrderFulfillmentQuantityService::class);
    $progress = $service->forOrder($order->refresh())->sole();

    expect($progress->orderedBase)->toBe(10.0)
        ->and($progress->shortClosedBase)->toBe(1.0)
        ->and($progress->plannedBase)->toBe(5.0)
        ->and($progress->reservedBase)->toBe(2.0)
        ->and($progress->readyBase)->toBe(2.0)
        ->and($progress->dispatchedBase)->toBe(3.0)
        ->and($progress->arrivedBase)->toBe(3.0)
        ->and($progress->returnedBase)->toBe(1.0)
        ->and($progress->invoicedBase)->toBe(4.0)
        ->and($progress->remainingToPlanBase)->toBe(4.0);

    expect($service->totals($order->refresh()))->toBe([
        'ordered' => 10.0,
        'short_closed' => 1.0,
        'planned' => 5.0,
        'reserved' => 2.0,
        'ready' => 2.0,
        'dispatched' => 3.0,
        'arrived' => 3.0,
        'returned' => 1.0,
        'invoiced' => 4.0,
        'remaining' => 4.0,
    ]);

    expect($ready->stage)->toBe(OperationStage::Ready);
});
it('covers quantity helper fallbacks and non numeric guard', function (): void {
    $service = app(OrderFulfillmentQuantityService::class);

    $line = new InventoryOperationLine;
    $line->forceFill(['base_quantity' => null, 'quantity' => '2.500000']);

    expect(new ReflectionMethod(OrderFulfillmentQuantityService::class, 'baseQuantity')
        ->invoke($service, $line))->toBe(2.5)
        ->and(fn (): mixed => new ReflectionMethod(OrderFulfillmentQuantityService::class, 'floatValue')
            ->invoke($service, 'not-numeric'))
        ->toThrow(LogicException::class, 'must be numeric');
});
